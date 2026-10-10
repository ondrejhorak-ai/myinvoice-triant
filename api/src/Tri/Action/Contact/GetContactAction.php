<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetContactAction
{
    public function __construct(private readonly ClientRepository $clients, private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $sid = TriRequest::supplierId($request);
        $client = $this->clients->find($id);
        if (!$client || (int) $client['supplier_id'] !== $sid) {
            return Json::error($response, 'not_found', 'Kontakt nenalezen.', 404);
        }
        // Same business metric as the contact list: approved TRI offers, not invoice totals.
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) AS tri_jobs_count, MAX(j.created_at) AS tri_last_job_date,
            COALESCE(SUM(CASE WHEN j.status IN ('confirmed','completed') THEN v.subtotal END),0) AS tri_revenue
            FROM tri_jobs j LEFT JOIN tri_quote_variants v ON v.id = j.approved_variant_id
            WHERE j.supplier_id = ? AND j.archived_at IS NULL
            AND (j.customer_client_id = ? OR EXISTS (SELECT 1 FROM tri_job_contacts jc WHERE jc.job_id = j.id AND jc.client_id = ?))");
        $stmt->execute([$sid, $id, $id]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
        $client['tri_jobs_count'] = (int) $stats['tri_jobs_count'];
        $client['tri_revenue'] = (float) $stats['tri_revenue'];
        $client['tri_last_job_date'] = $stats['tri_last_job_date'];
        return Json::ok($response, $client);
    }
}
