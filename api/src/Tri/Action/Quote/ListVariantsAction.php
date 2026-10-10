<?php

declare(strict_types=1);
namespace MyInvoice\Tri\Action\Quote;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tri\Support\TriRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
final class ListVariantsAction
{
    public function __construct(private readonly Connection $db) {}
    public function __invoke(Request $request,Response $response): Response
    {
        $q=$request->getQueryParams();$page=max(1,(int)($q['page']??1));$where=['j.supplier_id=?','j.archived_at IS NULL'];$args=[TriRequest::supplierId($request)];
        if (!empty($q['q'])) { $where[]='(v.number LIKE ? OR v.name LIKE ? OR j.title LIKE ? OR c.company_name LIKE ?)';$like='%'.addcslashes($q['q'],'%_\\').'%';array_push($args,$like,$like,$like,$like); }
        if (!empty($q['status'])) {$where[]='v.status=?';$args[]=$q['status'];}
        $from=' FROM tri_quote_variants v JOIN tri_jobs j ON j.id=v.job_id LEFT JOIN clients c ON c.id=j.customer_client_id WHERE '.implode(' AND ',$where);
        $s=$this->db->pdo()->prepare('SELECT COUNT(*)'.$from);$s->execute($args);$total=(int)$s->fetchColumn();
        $s=$this->db->pdo()->prepare('SELECT v.id,v.job_id,v.number,v.name,v.variant_code,v.status,v.subtotal,v.total_with_vat,v.job_date,j.title AS job_title,c.company_name AS customer_name'.$from.' ORDER BY v.id DESC LIMIT 25 OFFSET '.(($page-1)*25));$s->execute($args);
        return Json::ok($response,['data'=>$s->fetchAll(PDO::FETCH_ASSOC),'meta'=>['total'=>$total,'pages'=>(int)ceil($total/25),'page'=>$page]]);
    }
}
