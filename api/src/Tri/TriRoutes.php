<?php

declare(strict_types=1);

namespace MyInvoice\Tri;

use MyInvoice\Tri\Action\Job\ArchiveJobAction;
use MyInvoice\Tri\Action\Job\CheckJobNumberAction;
use MyInvoice\Tri\Action\Job\CreateJobAction;
use MyInvoice\Tri\Action\Job\DeleteJobAction;
use MyInvoice\Tri\Action\Job\GetJobAction;
use MyInvoice\Tri\Action\Job\ListJobsAction;
use MyInvoice\Tri\Action\Job\SuggestJobNumberAction;
use MyInvoice\Tri\Action\Job\UpdateJobAction;
use MyInvoice\Tri\Action\Job\UpdateJobStatusAction;
use MyInvoice\Tri\Action\Quote\ApproveVariantAction;
use MyInvoice\Tri\Action\Quote\CreateVariantAction;
use MyInvoice\Tri\Action\Quote\DuplicateVariantAction;
use MyInvoice\Tri\Action\Quote\GetVariantAction;
use MyInvoice\Tri\Action\Quote\SaveVariantAction;
use MyInvoice\Tri\Action\Quote\UpdateVariantStatusAction;
use MyInvoice\Tri\Action\Tag\CreateTagAction;
use MyInvoice\Tri\Action\Tag\DeleteTagAction;
use MyInvoice\Tri\Action\Tag\GetClientTagsAction;
use MyInvoice\Tri\Action\Tag\ListTagsAction;
use MyInvoice\Tri\Action\Tag\SetClientTagsAction;
use MyInvoice\Tri\Action\Tag\UpdateTagAction;
use MyInvoice\Tri\Action\Invoice\CreateAdvanceInvoiceAction;
use MyInvoice\Tri\Action\Invoice\CreateFinalInvoiceAction;
use MyInvoice\Tri\Action\Invoice\GetInvoiceJobAction;
use MyInvoice\Tri\Action\Invoice\ListJobInvoicesAction;
use MyInvoice\Tri\Action\Invoice\ListTriInvoicesAction;
use MyInvoice\Tri\Action\Invoice\SetInvoiceJobAction;
use Slim\App;

final class TriRoutes
{
    public static function register(App $app): void
    {
        // Tags
        $app->get('/api/tri/tags', ListTagsAction::class);
        $app->post('/api/tri/tags', CreateTagAction::class);
        $app->put('/api/tri/tags/{id:[0-9]+}', UpdateTagAction::class);
        $app->delete('/api/tri/tags/{id:[0-9]+}', DeleteTagAction::class);
        $app->get('/api/tri/clients/{id:[0-9]+}/tags', GetClientTagsAction::class);
        $app->put('/api/tri/clients/{id:[0-9]+}/tags', SetClientTagsAction::class);

        // Jobs (Zakázky TRI)
        $app->get('/api/tri/jobs', ListJobsAction::class);
        $app->get('/api/tri/jobs/suggest-number', SuggestJobNumberAction::class);
        $app->get('/api/tri/jobs/check-number', CheckJobNumberAction::class);
        $app->post('/api/tri/jobs', CreateJobAction::class);
        $app->get('/api/tri/jobs/{id:[0-9]+}', GetJobAction::class);
        $app->put('/api/tri/jobs/{id:[0-9]+}', UpdateJobAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/status', UpdateJobStatusAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/archive', ArchiveJobAction::class);
        $app->delete('/api/tri/jobs/{id:[0-9]+}', DeleteJobAction::class);

        // Quote variants
        $app->post('/api/tri/jobs/{job_id:[0-9]+}/variants', CreateVariantAction::class);
        $app->get('/api/tri/variants/{id:[0-9]+}', GetVariantAction::class);
        $app->put('/api/tri/variants/{id:[0-9]+}', SaveVariantAction::class);
        $app->post('/api/tri/variants/{id:[0-9]+}/duplicate', DuplicateVariantAction::class);
        $app->post('/api/tri/variants/{id:[0-9]+}/status', UpdateVariantStatusAction::class);
        $app->post('/api/tri/variants/{id:[0-9]+}/approve', ApproveVariantAction::class);

        // Job invoices (Faktury TRI ↔ Zakázky TRI)
        $app->get('/api/tri/jobs/{id:[0-9]+}/invoices', ListJobInvoicesAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/invoices/advance', CreateAdvanceInvoiceAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/invoices/final', CreateFinalInvoiceAction::class);
        $app->get('/api/tri/invoices', ListTriInvoicesAction::class);
        $app->get('/api/tri/invoices/{id:[0-9]+}/job', GetInvoiceJobAction::class);
        $app->put('/api/tri/invoices/{id:[0-9]+}/job', SetInvoiceJobAction::class);
    }
}
