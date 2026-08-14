<?php

declare(strict_types=1);

namespace MyInvoice\Tri;

use MyInvoice\Tri\Action\Activity\CreateJobCommentAction;
use MyInvoice\Tri\Action\Activity\DeleteJobCommentAction;
use MyInvoice\Tri\Action\Activity\ListJobActivityAction;
use MyInvoice\Tri\Action\Activity\UpdateJobCommentAction;
use MyInvoice\Tri\Action\Job\ArchiveJobAction;
use MyInvoice\Tri\Action\Job\CheckJobNumberAction;
use MyInvoice\Tri\Action\Job\CreateJobAction;
use MyInvoice\Tri\Action\Job\DeleteJobAction;
use MyInvoice\Tri\Action\Job\GetJobAction;
use MyInvoice\Tri\Action\Job\ListJobUsersAction;
use MyInvoice\Tri\Action\Job\ListJobsAction;
use MyInvoice\Tri\Action\Job\SuggestJobNumberAction;
use MyInvoice\Tri\Action\Job\UpdateJobAction;
use MyInvoice\Tri\Action\Job\UpdateJobStatusAction;
use MyInvoice\Tri\Action\Quote\ApproveVariantAction;
use MyInvoice\Tri\Action\Quote\CreateVariantAction;
use MyInvoice\Tri\Action\Quote\DuplicateVariantAction;
use MyInvoice\Tri\Action\Quote\GetVariantAction;
use MyInvoice\Tri\Action\Quote\GetLineImageAction;
use MyInvoice\Tri\Action\Quote\PdfAction as QuotePdfAction;
use MyInvoice\Tri\Action\Quote\SaveVariantAction;
use MyInvoice\Tri\Action\Quote\UploadLineImageAction;
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
use MyInvoice\Tri\Action\PriceList\CreatePriceListAction;
use MyInvoice\Tri\Action\PriceList\DeletePriceListAction;
use MyInvoice\Tri\Action\PriceList\GetPriceListAction;
use MyInvoice\Tri\Action\PriceList\ListPriceListsAction;
use MyInvoice\Tri\Action\PriceList\PdfAction as PriceListPdfAction;
use MyInvoice\Tri\Action\PriceList\SearchPriceListItemsAction;
use MyInvoice\Tri\Action\PriceList\UpdatePriceListAction;
use MyInvoice\Tri\Action\PriceList\UploadPriceListImageAction;
use MyInvoice\Tri\Action\Calendar\CreateCalendarEventAction;
use MyInvoice\Tri\Action\Calendar\DeleteCalendarEventAction;
use MyInvoice\Tri\Action\Calendar\GetCalendarEventAction;
use MyInvoice\Tri\Action\Calendar\ListCalendarEventsAction;
use MyInvoice\Tri\Action\Calendar\ListJobCalendarEventsAction;
use MyInvoice\Tri\Action\Calendar\UpdateCalendarEventAction;
use MyInvoice\Tri\Action\Traveler\GenerateTravelersAction;
use MyInvoice\Tri\Action\Traveler\GetTravelerAction;
use MyInvoice\Tri\Action\Traveler\JobPdfAction as JobTravelersPdfAction;
use MyInvoice\Tri\Action\Traveler\ListTravelersAction;
use MyInvoice\Tri\Action\Traveler\PdfAction as TravelerPdfAction;
use MyInvoice\Tri\Action\Traveler\UpdateTravelerOperationsAction;
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
        $app->get('/api/tri/users', ListJobUsersAction::class);
        $app->get('/api/tri/jobs', ListJobsAction::class);
        $app->get('/api/tri/jobs/suggest-number', SuggestJobNumberAction::class);
        $app->get('/api/tri/jobs/check-number', CheckJobNumberAction::class);
        $app->post('/api/tri/jobs', CreateJobAction::class);
        $app->get('/api/tri/jobs/{id:[0-9]+}', GetJobAction::class);
        $app->put('/api/tri/jobs/{id:[0-9]+}', UpdateJobAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/status', UpdateJobStatusAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/archive', ArchiveJobAction::class);
        $app->delete('/api/tri/jobs/{id:[0-9]+}', DeleteJobAction::class);

        // Job activity (chat + log událostí)
        $app->get('/api/tri/jobs/{id:[0-9]+}/activity', ListJobActivityAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/activity', CreateJobCommentAction::class);
        $app->put('/api/tri/activity/{id:[0-9]+}', UpdateJobCommentAction::class);
        $app->delete('/api/tri/activity/{id:[0-9]+}', DeleteJobCommentAction::class);

        // Quote variants
        $app->post('/api/tri/jobs/{job_id:[0-9]+}/variants', CreateVariantAction::class);
        $app->get('/api/tri/variants/{id:[0-9]+}', GetVariantAction::class);
        $app->get('/api/tri/variants/{id:[0-9]+}/pdf', QuotePdfAction::class);
        $app->post('/api/tri/variants/{id:[0-9]+}/images', UploadLineImageAction::class);
        $app->get('/api/tri/quote-images/{id:[0-9]+}', GetLineImageAction::class);
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

        // Price lists (Ceníky TRI)
        $app->get('/api/tri/price-lists', ListPriceListsAction::class);
        $app->get('/api/tri/catalog-items', SearchPriceListItemsAction::class);
        $app->post('/api/tri/price-lists', CreatePriceListAction::class);
        $app->get('/api/tri/price-lists/{id:[0-9]+}', GetPriceListAction::class);
        $app->get('/api/tri/price-lists/{id:[0-9]+}/pdf', PriceListPdfAction::class);
        $app->put('/api/tri/price-lists/{id:[0-9]+}', UpdatePriceListAction::class);
        $app->delete('/api/tri/price-lists/{id:[0-9]+}', DeletePriceListAction::class);
        $app->post('/api/tri/price-lists/{id:[0-9]+}/images', UploadPriceListImageAction::class);

        // Travelers (Průvodky TRI)
        $app->get('/api/tri/travelers', ListTravelersAction::class);
        $app->get('/api/tri/travelers/{id:[0-9]+}', GetTravelerAction::class);
        $app->put('/api/tri/travelers/{id:[0-9]+}/operations', UpdateTravelerOperationsAction::class);
        $app->get('/api/tri/travelers/{id:[0-9]+}/pdf', TravelerPdfAction::class);
        $app->get('/api/tri/jobs/{id:[0-9]+}/travelers', ListTravelersAction::class);
        $app->get('/api/tri/jobs/{id:[0-9]+}/travelers/pdf', JobTravelersPdfAction::class);
        $app->post('/api/tri/jobs/{id:[0-9]+}/travelers/generate', GenerateTravelersAction::class);

        // Calendar (Kalendář TRI)
        $app->get('/api/tri/calendar', ListCalendarEventsAction::class);
        $app->post('/api/tri/calendar', CreateCalendarEventAction::class);
        $app->get('/api/tri/calendar/{id:[0-9]+}', GetCalendarEventAction::class);
        $app->put('/api/tri/calendar/{id:[0-9]+}', UpdateCalendarEventAction::class);
        $app->delete('/api/tri/calendar/{id:[0-9]+}', DeleteCalendarEventAction::class);
        $app->get('/api/tri/jobs/{id:[0-9]+}/calendar', ListJobCalendarEventsAction::class);
    }
}
