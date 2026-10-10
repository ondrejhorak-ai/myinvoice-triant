<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Service\PriceReleaseRules;
use PDO;

/** The list row is the transaction mutex for draft creation, saving and publication. */
final class PricingRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    private function rows(string $sql, array $args = []): array
    {
        $s = $this->db->pdo()->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function execute(string $sql, array $args = []): void
    {
        $this->db->pdo()->prepare($sql)->execute($args);
    }

    private function transaction(callable $run): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $run();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function listRow(int $sid, int $id, bool $lock = false): array
    {
        $r = $this->rows('SELECT * FROM tri_price_lists WHERE supplier_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''), [$sid, $id]);
        if (!$r) {
            throw new \OutOfBoundsException('Ceník nenalezen.');
        }
        return $r[0];
    }

    private function images(array $products, int $sid): array
    {
        $ids = array_values(array_unique(array_filter(array_column($products, 'image_id'))));
        $images = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->rows("SELECT id,sha256,width_px,height_px,size_bytes FROM tri_quote_images WHERE supplier_id=? AND id IN ($marks)", [$sid,...$ids]) as $i) {
                $images[$i['id']] = ['id' => (int)$i['id'],'url' => \MyInvoice\Tri\Service\QuoteImageService::url((int)$i['id'], $i['sha256']),'width_px' => (int)$i['width_px'],'height_px' => (int)$i['height_px'],'size_bytes' => (int)$i['size_bytes']];
            }
        }
        foreach ($products as &$p) {
            $p['image'] = $images[$p['image_id'] ?? 0] ?? null;
        }
        return $products;
    }

    public function lists(int $sid, bool $includeArchived = false): array
    {
        return $this->rows("SELECT p.*,
            (SELECT r.sequence FROM tri_price_releases r WHERE r.price_list_id=p.id AND r.status='published' AND r.valid_from<=CURRENT_DATE AND (r.valid_until IS NULL OR r.valid_until>=CURRENT_DATE) ORDER BY sequence DESC LIMIT 1) AS current_sequence,
            (SELECT r.valid_until FROM tri_price_releases r WHERE r.price_list_id=p.id AND r.status='published' AND r.valid_from<=CURRENT_DATE AND (r.valid_until IS NULL OR r.valid_until>=CURRENT_DATE) ORDER BY sequence DESC LIMIT 1) AS valid_until,
            (SELECT r.valid_from FROM tri_price_releases r WHERE r.price_list_id=p.id AND r.status='published' AND r.valid_from>CURRENT_DATE ORDER BY valid_from LIMIT 1) AS scheduled_from,
            (SELECT COUNT(*) FROM tri_price_releases r WHERE r.price_list_id=p.id AND r.status='draft') AS has_draft,
            (SELECT MAX(r.valid_from) FROM tri_price_releases r WHERE r.price_list_id=p.id AND r.status='published' AND r.valid_from<=CURRENT_DATE AND (r.valid_until IS NULL OR r.valid_until>=CURRENT_DATE)) AS valid_from,
            COALESCE((SELECT COUNT(*) FROM tri_price_release_items i JOIN tri_price_releases r ON r.id=i.release_id WHERE r.id=(SELECT r2.id FROM tri_price_releases r2 WHERE r2.price_list_id=p.id ORDER BY r2.sequence DESC LIMIT 1)),0) AS item_count
            FROM tri_price_lists p WHERE supplier_id=? AND (?=1 OR archived_at IS NULL) ORDER BY name,id", [$sid,(int)$includeArchived]);
    }

    public function archive(int $sid, int $id, bool $archived): array
    {
        $this->transaction(function () use ($sid, $id, $archived): void {
            $this->listRow($sid, $id, true);
            $this->execute('UPDATE tri_price_lists SET archived_at=? WHERE supplier_id=? AND id=?', [$archived ? date('Y-m-d H:i:s') : null,$sid,$id]);
        });
        return $this->detail($sid, $id);
    }

    public function catalog(int $sid, array $query): array
    {
        $q = '%' . addcslashes(trim((string) ($query['q'] ?? '')), '%_\\') . '%';
        $where = 'supplier_id=? AND (code LIKE ? OR title LIKE ? OR category LIKE ? OR tags_json LIKE ?)';
        $args = [$sid, $q, $q, $q, $q];
        if (!empty($query['category'])) {
            $where .= ' AND category=?';
            $args[] = $query['category'];
        }
        $total = (int) $this->rows('SELECT COUNT(*) AS n FROM tri_catalog_products WHERE ' . $where, $args)[0]['n'];
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        return ['data' => $this->images($this->rows('SELECT * FROM tri_catalog_products WHERE ' . $where . ' ORDER BY code,id LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $args), $sid), 'total' => $total];
    }

    public function productPrices(int $sid, int $id): array
    {
        if (!$this->rows('SELECT id FROM tri_catalog_products WHERE supplier_id=? AND id=?', [$sid,$id])) {
            throw new \OutOfBoundsException('Položka nenalezena.');
        }
        return $this->rows("SELECT p.id AS price_list_id,p.name,p.currency,r.id AS release_id,r.sequence,r.status,r.valid_from,r.valid_until,i.unit_price,i.price_changed_at,u.name AS author FROM tri_price_release_items i JOIN tri_price_releases r ON r.id=i.release_id JOIN tri_price_lists p ON p.id=r.price_list_id LEFT JOIN users u ON u.id=r.published_by WHERE i.product_id=? AND p.supplier_id=? ORDER BY p.name,r.sequence DESC", [$id,$sid]);
    }

    public function product(int $sid, int $id, array $body): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            return $this->transaction(fn (): array => $this->product($sid, $id, $body));
        }
        $code = trim((string) ($body['code'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $unit = trim((string) ($body['unit'] ?? ''));
        $vat = $body['vat_rate'] ?? 21;
        $qty = $body['default_quantity'] ?? 1;
        if ($code === '' || mb_strlen($code) > 160 || $title === '' || mb_strlen($title) > 500 || $unit === '' || mb_strlen($unit) > 20
            || !is_numeric($vat) || (float)$vat < 0 || (float)$vat > 100 || !is_numeric($qty) || (float)$qty <= 0 || (float)$qty > 999999999) {
            throw new \DomainException('Vyplňte kód, název, jednotku a platné množství / DPH.');
        }
        $image = !empty($body['image_id']) ? (int)$body['image_id'] : null;
        if ($image && !$this->rows('SELECT id FROM tri_quote_images WHERE supplier_id=? AND id=?', [$sid,$image])) {
            throw new \DomainException('Neplatný obrázek.');
        }
        if ($id) {
            $current = $this->rows('SELECT code,unit FROM tri_catalog_products WHERE supplier_id=? AND id=? FOR UPDATE', [$sid,$id])[0] ?? null;
            if ($current && ($current['code'] !== $code || $current['unit'] !== $unit) && $this->rows('SELECT 1 FROM tri_price_release_items WHERE product_id=? LIMIT 1', [$id])) {
                throw new \DomainException('Kód a jednotku položky použité ve vydání nelze měnit. Vytvořte samostatnou položku.');
            }
        }
        $values = [$code,$title,$body['description'] ?? null,$unit,$vat,$qty,mb_substr(trim((string)($body['category'] ?? '')), 0, 190),json_encode(array_values(array_filter(array_map('strval', (array)($body['tags'] ?? []))))),$body['parameters'] ?? null,$image];
        try {
            if ($id) {
                $s = $this->db->pdo()->prepare('UPDATE tri_catalog_products SET code=?,title=?,description=?,unit=?,vat_rate=?,default_quantity=?,category=?,tags_json=?,parameters=?,image_id=?,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND supplier_id=? AND lock_version=?');
                $s->execute([...$values,$id,$sid,(int)($body['lock_version'] ?? -1)]);
                if (!$s->rowCount()) {
                    throw new \UnexpectedValueException('Položku změnil jiný uživatel. Obnovte ji.');
                }
            } else {
                $this->execute('INSERT INTO tri_catalog_products (code,title,description,unit,vat_rate,default_quantity,category,tags_json,parameters,image_id,supplier_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [...$values,$sid]);
                $id = (int)$this->db->pdo()->lastInsertId();
            }
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new \DomainException('Kód položky už existuje.');
            }
            throw $e;
        }
        return $this->images($this->rows('SELECT * FROM tri_catalog_products WHERE supplier_id=? AND id=?', [$sid,$id]), $sid)[0];
    }

    public function detail(int $sid, int $id, int $releaseId = 0): array
    {
        $list = $this->listRow($sid, $id);
        $list['releases'] = $this->rows('SELECT * FROM tri_price_releases WHERE price_list_id=? ORDER BY sequence DESC', [$id]);
        $list['release'] = null;
        foreach ($list['releases'] as $r) {
            if (($releaseId && (int)$r['id'] === $releaseId) || (!$releaseId && ($r['status'] === 'draft' || ($r['valid_from'] <= date('Y-m-d') && (!$r['valid_until'] || $r['valid_until'] >= date('Y-m-d')))))) {
                $list['release'] = $r;
                break;
            }
        }
        if (!$releaseId) {
            foreach ($list['releases'] as $r) {
                if ($r['status'] === 'published' && $r['valid_from'] <= date('Y-m-d') && (!$r['valid_until'] || $r['valid_until'] >= date('Y-m-d'))) {
                    $list['release'] = $r;
                    break;
                }
            }
        }
        if ($releaseId && !$list['release']) {
            throw new \OutOfBoundsException('Vydání nenalezeno.');
        }
        if (!$list['release'] && !$releaseId) {
            $list['release'] = $list['releases'][0] ?? null;
        }
        $list['items'] = [];
        if ($list['release']) {
            foreach ($this->rows('SELECT * FROM tri_price_release_items WHERE release_id=? ORDER BY sort_order,product_id', [$list['release']['id']]) as $r) {
                $list['items'][] = array_merge(json_decode($r['snapshot_json'], true, 512, JSON_THROW_ON_ERROR), $r);
            }
        }
        $list['items'] = $this->images($list['items'], $sid);
        $list['legacy_item_count'] = (int)$this->rows('SELECT COUNT(*) n FROM tri_price_list_items WHERE price_list_id=?', [$id])[0]['n'];
        return $list;
    }

    public function release(int $sid, int $rid): array
    {
        $r = $this->rows('SELECT r.price_list_id FROM tri_price_releases r JOIN tri_price_lists p ON p.id=r.price_list_id WHERE p.supplier_id=? AND r.id=?', [$sid,$rid]);
        if (!$r) {
            throw new \OutOfBoundsException('Vydání nenalezeno.');
        }
        return $this->detail($sid, (int)$r[0]['price_list_id'], $rid);
    }

    public function create(int $sid, array $body): int
    {
        $name = trim((string)($body['name'] ?? ''));
        $currency = strtoupper((string)($body['currency'] ?? 'CZK'));
        if ($name === '' || mb_strlen($name) > 190 || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new \DomainException('Neplatný název nebo měna.');
        }
        $this->execute('INSERT INTO tri_price_lists (supplier_id,name,note,currency) VALUES (?,?,?,?)', [$sid,$name,$body['note'] ?? null,$currency]);
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function draft(int $sid, int $id): array
    {
        $rid = $this->transaction(function () use ($sid, $id): int {
            $list = $this->listRow($sid, $id, true);
            if ($list['archived_at']) {
                throw new \DomainException('Ceník je archivovaný.');
            }
            $existing = $this->rows("SELECT id FROM tri_price_releases WHERE price_list_id=? AND status='draft'", [$id]);
            if ($existing) {
                return (int)$existing[0]['id'];
            }
            $source = $this->rows("SELECT * FROM tri_price_releases WHERE price_list_id=? AND status='published' ORDER BY sequence DESC LIMIT 1", [$id])[0] ?? null;
            $this->execute('INSERT INTO tri_price_releases (price_list_id,sequence,source_release_id) VALUES (?,?,?)', [$id,($source['sequence'] ?? 0) + 1,$source['id'] ?? null]);
            $rid = (int)$this->db->pdo()->lastInsertId();
            if ($source) {
                $this->execute('INSERT INTO tri_price_release_items (release_id,product_id,unit_price,price_changed_at,sort_order,snapshot_json) SELECT ?,product_id,unit_price,price_changed_at,sort_order,snapshot_json FROM tri_price_release_items WHERE release_id=?', [$rid,$source['id']]);
                foreach ($this->rows('SELECT p.* FROM tri_catalog_products p JOIN tri_price_release_items i ON i.product_id=p.id WHERE i.release_id=? AND p.supplier_id=?', [$rid,$sid]) as $product) {
                    $this->execute('UPDATE tri_price_release_items SET snapshot_json=? WHERE release_id=? AND product_id=?', [json_encode($product, JSON_THROW_ON_ERROR),$rid,$product['id']]);
                }
            } else {
                foreach ($this->rows('SELECT * FROM tri_price_list_items WHERE price_list_id=? ORDER BY sort_order,id', [$id]) as $i) {
                    $product = $this->rows('SELECT * FROM tri_catalog_products WHERE supplier_id=? AND legacy_item_id=?', [$sid,$i['id']])[0] ?? null;
                    if (!$product) {
                        // Do not infer identity from duplicate legacy designations: retain a stable disambiguating suffix.
                        $code = mb_substr(trim($i['designation']) ?: 'ITEM', 0, 130).'~L'.$id.'-'.$i['id'];
                        $product = $this->product($sid, 0, ['code' => $code,'title' => $i['title'],'description' => $i['description'],'unit' => $i['unit'],'vat_rate' => $i['vat_rate'],'default_quantity' => $i['default_quantity'],'image_id' => $i['image_id']]);
                        $this->execute('UPDATE tri_catalog_products SET legacy_item_id=? WHERE id=?', [$i['id'],$product['id']]);
                    }
                    $this->execute('INSERT INTO tri_price_release_items (release_id,product_id,unit_price,price_changed_at,sort_order,snapshot_json) VALUES (?,?,?,?,?,?)', [$rid,$product['id'],$i['base_unit_price'],$i['price_updated_at'],$i['sort_order'],json_encode($product, JSON_THROW_ON_ERROR)]);
                }
            }
            return $rid;
        });
        return $this->detail($sid, $id, $rid);
    }

    public function save(int $sid, int $id, int $rid, array $body): array
    {
        $this->transaction(function () use ($sid, $id, $rid, $body): void {
            if ($this->listRow($sid, $id, true)['archived_at']) {
                throw new \DomainException('Ceník je archivovaný.');
            }
            $r = $this->rows("SELECT * FROM tri_price_releases WHERE id=? AND price_list_id=? AND status='draft'", [$rid,$id])[0] ?? null;
            if (!$r || (int)$r['lock_version'] !== (int)($body['lock_version'] ?? -1)) {
                throw new \UnexpectedValueException('Koncept změnil jiný uživatel nebo již byl publikován. Obnovte stránku.');
            }
            $from = PriceReleaseRules::date($body['valid_from'] ?? null);
            $until = PriceReleaseRules::date($body['valid_until'] ?? null);
            if ($from && $until && $until < $from) {
                throw new \DomainException('Konec platnosti musí být po začátku.');
            }
            if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) > 10000) {
                throw new \DomainException('Neplatný seznam položek.');
            }
            $old = [];
            foreach ($this->rows('SELECT * FROM tri_price_release_items WHERE release_id=?', [$rid]) as $i) {
                $old[(int)$i['product_id']] = $i;
            }
            $new = [];
            foreach ($body['items'] as $i) {
                if (!is_array($i)) {
                    throw new \DomainException('Neplatná položka.');
                }
                $pid = (int)($i['product_id'] ?? 0);
                if (isset($new[$pid])) {
                    throw new \DomainException('Duplicitní položka.');
                }
                $p = $this->rows('SELECT * FROM tri_catalog_products WHERE supplier_id=? AND id=? FOR UPDATE', [$sid,$pid])[0] ?? null;
                if (!$p) {
                    throw new \DomainException('Položka katalogu nenalezena.');
                }
                $price = PriceReleaseRules::money($i['unit_price'] ?? null);
                $new[$pid] = [$price,($old[$pid]['unit_price'] ?? null) === $price ? ($old[$pid]['price_changed_at'] ?? null) : date('Y-m-d H:i:s'),isset($old[$pid]) ? $old[$pid]['snapshot_json'] : json_encode($p, JSON_THROW_ON_ERROR)];
            }
            $this->execute('DELETE FROM tri_price_release_items WHERE release_id=?', [$rid]);
            $sort = 0;
            foreach ($new as $pid => [$price,$changed,$snapshot]) {
                $this->execute('INSERT INTO tri_price_release_items (release_id,product_id,unit_price,price_changed_at,sort_order,snapshot_json) VALUES (?,?,?,?,?,?)', [$rid,$pid,$price,$changed,$sort++,$snapshot]);
            }
            $this->execute('UPDATE tri_price_releases SET valid_from=?,valid_until=?,lock_version=lock_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?', [$from,$until,$rid]);
        });
        return $this->detail($sid, $id, $rid);
    }

    public function publish(int $sid, int $id, int $rid, int $version, int $uid): array
    {
        $this->transaction(function () use ($sid, $id, $rid, $version, $uid): void {
            if ($this->listRow($sid, $id, true)['archived_at']) {
                throw new \DomainException('Ceník je archivovaný.');
            }
            $r = $this->rows("SELECT * FROM tri_price_releases WHERE id=? AND price_list_id=? AND status='draft'", [$rid,$id])[0] ?? null;
            if (!$r || (int)$r['lock_version'] !== $version) {
                throw new \UnexpectedValueException('Koncept změnil jiný uživatel. Obnovte stránku.');
            }
            $published = $this->rows("SELECT * FROM tri_price_releases WHERE price_list_id=? AND status='published'", [$id]);
            PriceReleaseRules::publication($r, $published, date('Y-m-d'));
            $items = $this->rows('SELECT unit_price FROM tri_price_release_items WHERE release_id=?', [$rid]);
            if (!$items || count(array_filter($items, fn ($i) => $i['unit_price'] === null))) {
                throw new \DomainException('Vydání musí mít položky a všechny ceny vyplněné.');
            }
            if ($r['source_release_id']) {
                $this->execute('UPDATE tri_price_release_items n JOIN tri_price_release_items p ON p.release_id=? AND p.product_id=n.product_id SET n.price_changed_at=CASE WHEN n.unit_price=p.unit_price THEN p.price_changed_at ELSE CURRENT_TIMESTAMP END WHERE n.release_id=?', [$r['source_release_id'],$rid]);
            }
            $previous = (new \DateTimeImmutable($r['valid_from']))->modify('-1 day')->format('Y-m-d');
            $this->execute("UPDATE tri_price_releases SET valid_until=? WHERE price_list_id=? AND status='published' AND valid_from<? AND (valid_until IS NULL OR valid_until>=?)",[$previous,$id,$r['valid_from'],$r['valid_from']]);
            $this->execute("UPDATE tri_price_releases SET status='published',published_by=?,published_at=CURRENT_TIMESTAMP,lock_version=lock_version+1 WHERE id=?",[$uid,$rid]);
        });
        return $this->detail($sid,$id,$rid);
    }
}
