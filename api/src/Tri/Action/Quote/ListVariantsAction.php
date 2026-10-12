<?php

declare(strict_types=1);
namespace MyInvoice\Tri\Action\Quote;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Contact\JobContactOrder;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
final class ListVariantsAction
{
    public function __construct(private readonly Connection $db) {}
    public function __invoke(Request $request,Response $response): Response
    {
        $q=$request->getQueryParams();$page=max(1,(int)($q['page']??1));$where=['j.supplier_id=?','j.archived_at IS NULL'];$args=[TriRequest::supplierId($request)];
        if (!empty($q['q'])) {
            $like='%'.addcslashes((string) $q['q'],'%_\\').'%';
            $where[]='(v.number LIKE ? OR v.name LIKE ? OR j.title LIKE ? OR '.JobContactOrder::columnMatchSql('j').')';
            array_push($args,$like,$like,$like,...JobContactOrder::columnMatchParams($like));
        }
        if (!empty($q['status'])) {$where[]='v.status=?';$args[]=$q['status'];}
        $from=' FROM tri_quote_variants v JOIN tri_jobs j ON j.id=v.job_id WHERE '.implode(' AND ',$where);
        $pdo=$this->db->pdo();
        $s=$pdo->prepare('SELECT COUNT(*)'.$from);$s->execute($args);$total=(int)$s->fetchColumn();
        $s=$pdo->prepare('SELECT v.id,v.job_id,v.number,v.name,v.variant_code,v.status,v.subtotal,v.total_with_vat,v.job_date,j.title AS job_title'.$from.' ORDER BY v.id DESC LIMIT 25 OFFSET '.(($page-1)*25));$s->execute($args);
        $rows=$s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $labels=JobContactOrder::labelsForJobs($pdo, array_column($rows, 'job_id'));
        foreach ($rows as &$row) {
            $row['customer_name']=$labels[(int) $row['job_id']] ?? null;
        }
        unset($row);
        return Json::ok($response,['data'=>$rows,'meta'=>['total'=>$total,'pages'=>(int)ceil($total/25),'page'=>$page]]);
    }
}
