<?php

declare(strict_types=1);

namespace MyInvoice;

use MyInvoice\Action\AresVies\AresLookupAction;
use MyInvoice\Action\AresVies\CrpDphLookupAction;
use MyInvoice\Action\AresVies\ViesLookupAction;
use MyInvoice\Action\Auth\ChangePasswordAction;
use MyInvoice\Action\Client\ArchiveClientAction;
use MyInvoice\Action\Client\CreateClientAction;
use MyInvoice\Action\Client\DeleteClientAction;
use MyInvoice\Action\Client\GetClientAction;
use MyInvoice\Action\Client\ClientVatStatusAction;
use MyInvoice\Action\Client\ListClientsAction;
use MyInvoice\Action\Client\UpdateClientAction;
use MyInvoice\Action\Codebook\CodebookAction;
use MyInvoice\Action\Admin\EmailTemplateAction;
use MyInvoice\Action\Admin\ExportAction;
use MyInvoice\Action\Admin\InvoicesZipAction;
use MyInvoice\Action\Admin\CronJobsAction;
use MyInvoice\Action\Admin\RunCronJobAction;
use MyInvoice\Action\Admin\ListActivityLogAction;
use MyInvoice\Action\Admin\ListSentEmailsAction;
use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Settings\EmailBrandingAction;
use MyInvoice\Action\Settings\BrandingProfilesAction;
use MyInvoice\Action\Settings\EmailProfilesAction;
use MyInvoice\Action\Settings\NaceCodesAction;
use MyInvoice\Action\Settings\PdfSigningDiagnosticsAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Action\Settings\SignatureDocumentSelectionAction;
use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Action\Settings\TriSidebarSettingsAction;
use MyInvoice\Action\Settings\SupplierInvoiceCounterAction;
use MyInvoice\Action\Invoice\ExportCsvAction;
use MyInvoice\Action\Invoice\ExportSelectedPdfAction;
use MyInvoice\Action\Invoice\InvoiceActivityAction;
use MyInvoice\Action\Invoice\GetInvoiceAction;
use MyInvoice\Action\Invoice\InvoiceIsdocAction;
use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\Invoice\PreviewVarsymbolAction;
use MyInvoice\Action\Invoice\ListPaymentsAction;
use MyInvoice\Action\Invoice\AdvanceCandidatesAction as InvoiceAdvanceCandidatesAction;
use MyInvoice\Action\Invoice\FinalCandidatesAction;
use MyInvoice\Action\Invoice\PdfAction;
use MyInvoice\Action\Invoice\PublicInvoiceAttachmentAction;
use MyInvoice\Action\Invoice\PublicInvoiceGetAction;
use MyInvoice\Action\Invoice\PublicInvoicePdfAction;
use MyInvoice\Action\Invoice\ListPdfsAction;
use MyInvoice\Action\Invoice\DownloadArchivedPdfAction;
use MyInvoice\Action\Invoice\DownloadImportedPdfAction;
use MyInvoice\Action\Invoice\Attachment\ListAttachmentsAction;
use MyInvoice\Action\Invoice\Attachment\DownloadAttachmentAction;
use MyInvoice\Action\Invoice\GetRecipientsAction;
use MyInvoice\Tri\Action\Invoice\CoreInvoiceWriteGoneAction;
use MyInvoice\Action\Auth\ApiMeAction;
use MyInvoice\Action\Auth\ForgotPasswordAction;
use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\LogoutAction;
use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Action\Auth\MfaStepUpAction;
use MyInvoice\Action\Auth\PasskeyAction;
use MyInvoice\Action\Auth\ResetPasswordAction;
use MyInvoice\Action\Auth\SessionAction;
use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Action\Auth\SetupAresLookupAction;
use MyInvoice\Action\Auth\SetupCrpDphLookupAction;
use MyInvoice\Action\Auth\SetupSampleAction;
use MyInvoice\Action\Auth\SetupStatusAction;
use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Action\Auth\Tokens\ListTokensAction;
use MyInvoice\Action\Auth\Tokens\RevokeTokenAction;
use MyInvoice\Action\Auth\TotpAction;
use MyInvoice\Action\System\HealthAction;
use MyInvoice\Action\System\OpenApiAction;
use MyInvoice\Action\System\VersionAction;
use MyInvoice\Action\Admin\UpdateAction;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health',  HealthAction::class);
        $app->get('/api/version', VersionAction::class);

        // Public REST API v1 — dokumentace
        $app->get('/api/openapi.yaml', [OpenApiAction::class, 'spec']);
        $app->get('/api/docs',         [OpenApiAction::class, 'docs']);       // Swagger UI (Try it out)
        $app->get('/api/reference',    [OpenApiAction::class, 'reference']);  // Redoc (pretty static)
        $app->get('/api/scalar',       [OpenApiAction::class, 'scalar']);     // Scalar (moderní reference)

        // Admin — kontrola a upgrade nové verze (M9, issue „Kontrola a upgrade")
        $app->get  ('/api/admin/update/status',  [UpdateAction::class, 'status']);
        $app->get  ('/api/admin/update/preflight', [UpdateAction::class, 'preflight']);
        $app->post ('/api/admin/update/refresh', [UpdateAction::class, 'refresh']);
        $app->post ('/api/admin/update/trigger', [UpdateAction::class, 'trigger']);
        $app->post ('/api/admin/update/cancel',  [UpdateAction::class, 'cancel']);

        // Admin — správa ukázkových (sample) dat (issue #162); admin-only přes RoleMiddleware
        $app->get   ('/api/maintenance/sample-data', [\MyInvoice\Action\Maintenance\SampleDataAction::class, 'status']);
        $app->delete('/api/maintenance/sample-data', [\MyInvoice\Action\Maintenance\SampleDataAction::class, 'delete']);

        $app->group('/api/auth', function ($g) {
            $g->get ('/setup-status',    SetupStatusAction::class);
            $g->post('/setup',           SetupAction::class);
            $g->post('/setup-ares-lookup', SetupAresLookupAction::class);  // public ARES proxy během setup wizardu
            $g->post('/setup-crpdph-lookup', SetupCrpDphLookupAction::class);  // public proxy do registru plátců DPH (účty z DIČ)
            $g->post('/setup-sample',    SetupSampleAction::class);         // public sample data generator (jen pokud nejsou data)
            $g->post('/login',           LoginAction::class);
            $g->post('/webauthn/login/options', [LoginAction::class, 'passkeyOptions']);
            $g->post('/logout',          LogoutAction::class);
            $g->get ('/me',              MeAction::class);
            $g->get ('/api-me',          ApiMeAction::class);  // connection-test pro bearer i session
            $g->post('/change-password', ChangePasswordAction::class);
            $g->post('/forgot',          ForgotPasswordAction::class);
            $g->post('/reset',           ResetPasswordAction::class);
            // TOTP (2FA)
            $g->get ('/totp/status',     [TotpAction::class, 'status']);
            $g->post('/totp/setup',      [TotpAction::class, 'setup']);
            $g->post('/totp/enable',     [TotpAction::class, 'enable']);
            // WebAuthn/passkeys — interní session-only self-service API
            $g->get   ('/webauthn/credentials',              [PasskeyAction::class, 'credentials']);
            $g->post  ('/webauthn/register/options',          [PasskeyAction::class, 'registerOptions']);
            $g->post  ('/webauthn/register/verify',           [PasskeyAction::class, 'registerVerify']);
            $g->post  ('/webauthn/login/verify',              [PasskeyAction::class, 'loginVerify']);
            $g->post  ('/webauthn/step-up/options',           [PasskeyAction::class, 'stepUpOptions']);
            $g->post  ('/webauthn/step-up/verify',            [PasskeyAction::class, 'stepUpVerify']);
            $g->patch ('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'rename']);
            $g->delete('/webauthn/credentials/{id:[0-9]+}',   [PasskeyAction::class, 'revoke']);
            $g->post  ('/mfa/step-up/totp',                   [MfaStepUpAction::class, 'totp']);
            $g->post  ('/mfa/step-up/recovery',               [MfaStepUpAction::class, 'recovery']);
            $g->get   ('/mfa/recovery-codes',                 [\MyInvoice\Action\Auth\MfaRecoveryCodeAction::class, 'status']);
            $g->post  ('/mfa/recovery-codes',                 [\MyInvoice\Action\Auth\MfaRecoveryCodeAction::class, 'generate']);
            $g->get   ('/session/status',                     [SessionAction::class, 'status']);
            $g->post  ('/session/activity',                   [SessionAction::class, 'activity']);
            $g->post  ('/session/lock',                       [SessionAction::class, 'lock']);
            $g->get   ('/session/lock-preference',            [SessionAction::class, 'lockPreference']);
            $g->put   ('/session/lock-preference',            [SessionAction::class, 'updateLockPreference']);
            $g->post  ('/session/unlock/options',             [SessionAction::class, 'unlockOptions']);
            $g->post  ('/session/unlock/verify',              [SessionAction::class, 'unlockVerify']);
            // API tokeny (Personal Access Tokens) — správa jen ze session auth
            $g->get   ('/tokens',                  ListTokensAction::class);
            $g->post  ('/tokens',                  CreateTokenAction::class);
            $g->delete('/tokens/{id:[0-9]+}',      RevokeTokenAction::class);
        });

        // ARES + VIES lookups (vyžadují auth)
        $app->post('/api/clients/lookup-ares', AresLookupAction::class);
        $app->post('/api/clients/lookup-vies', ViesLookupAction::class);
        $app->post('/api/clients/lookup-bank', CrpDphLookupAction::class);  // účty z DIČ přes registr plátců DPH

        // Globální vyhledávač pro sidebar (klienti/dodavatelé + vydané/přijaté faktury)
        $app->get('/api/search', \MyInvoice\Action\Search\GlobalSearchAction::class);
        $app->get('/api/branding-profiles', [BrandingProfilesAction::class, 'publicList']);

        // Codebooks
        $app->get('/api/codebooks/countries',  [CodebookAction::class, 'countries']);
        $app->get('/api/codebooks/currencies', [CodebookAction::class, 'currencies']);
        $app->get('/api/codebooks/vat-rates',  [CodebookAction::class, 'vatRates']);
        $app->get('/api/codebooks/units',      [CodebookAction::class, 'units']);
        $app->get('/api/codebooks/years',      [CodebookAction::class, 'years']);
        $app->get('/api/codebooks/cnb-rate',   \MyInvoice\Action\Codebook\CnbRateAction::class);

        // Clients
        $app->get   ('/api/clients',                 ListClientsAction::class);
        $app->post  ('/api/clients',                 \MyInvoice\Tri\Action\Contact\CoreClientWriteGoneAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}',     GetClientAction::class);
        $app->get   ('/api/clients/{id:[0-9]+}/vat-status', ClientVatStatusAction::class);  // online ARES/VIES plátcovství
        $app->put   ('/api/clients/{id:[0-9]+}',     \MyInvoice\Tri\Action\Contact\CoreClientWriteGoneAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/archive',   \MyInvoice\Tri\Action\Contact\CoreClientWriteGoneAction::class);
        $app->post  ('/api/clients/{id:[0-9]+}/unarchive', \MyInvoice\Tri\Action\Contact\CoreClientWriteGoneAction::class);
        $app->delete('/api/clients/{id:[0-9]+}',           \MyInvoice\Tri\Action\Contact\CoreClientWriteGoneAction::class);
        // Invoices (M3 — draft + editor + sumace; vystavení/odeslání/PDF přijde v M4)
        $app->get    ('/api/invoices',              ListInvoicesAction::class);
        $app->get    ('/api/invoices/export.csv',   ExportCsvAction::class);
        $app->get    ('/api/invoices/export.pdf',   ExportSelectedPdfAction::class);
        // Veřejný alias admin exportu (bearer allowlist pokrývá /api/invoices/*):
        // ?format=pdf-zip|isdoc|pohoda|stereo & month=YYYY-MM nebo period=quarterly&year&quarter
        $app->get    ('/api/invoices/export',       ExportAction::class);
        $app->get    ('/api/invoices/preview-varsymbol', PreviewVarsymbolAction::class);
        $app->post   ('/api/invoices',              CoreInvoiceWriteGoneAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}',  GetInvoiceAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/activity', InvoiceActivityAction::class);
        $app->put    ('/api/invoices/{id:[0-9]+}',  CoreInvoiceWriteGoneAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}',  CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue',     CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/mark-paid', CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/unmark-paid', CoreInvoiceWriteGoneAction::class);
        // Evidence plateb / částečné úhrady (#89) + daňový doklad k přijaté platbě (zálohy)
        $app->get    ('/api/invoices/{id:[0-9]+}/payments', ListPaymentsAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/payments', CoreInvoiceWriteGoneAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/payments/{paymentId:[0-9]+}', CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/payments/{paymentId:[0-9]+}/tax-document', CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/cancel',    CoreInvoiceWriteGoneAction::class);
        // Obnova snapshotů klienta/dodavatele z live dat (admin, i u vystavené) — BUG 5.
        $app->post   ('/api/invoices/{id:[0-9]+}/rebuild-snapshots', CoreInvoiceWriteGoneAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/isdoc',     InvoiceIsdocAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdf',       PdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs',      ListPdfsAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/pdfs/{archiveId:[0-9]+}', DownloadArchivedPdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/imported-pdf', DownloadImportedPdfAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments', ListAttachmentsAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/attachments', CoreInvoiceWriteGoneAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', DownloadAttachmentAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/attachments/{attId:[0-9]+}', CoreInvoiceWriteGoneAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/recipients', GetRecipientsAction::class);  // #86 vyřešení příjemců pro modal
        $app->post   ('/api/invoices/{id:[0-9]+}/send',      CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/send-test', CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder',  CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/reminder-test', CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/issue-final', CoreInvoiceWriteGoneAction::class);
        // Zpětné propojení daňového dokladu se zálohovou fakturou (proforma)
        $app->get    ('/api/invoices/{id:[0-9]+}/advance-candidates', InvoiceAdvanceCandidatesAction::class);
        $app->get    ('/api/invoices/{id:[0-9]+}/final-candidates',   FinalCandidatesAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/link-advance',       CoreInvoiceWriteGoneAction::class);
        $app->delete ('/api/invoices/{id:[0-9]+}/link-advance',       CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/bulk-reissue',          CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/bulk-reminder',         CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/clone',     CoreInvoiceWriteGoneAction::class);
        $app->get    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'get']);
        $app->put    ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'put']);
        $app->delete ('/api/documents/{entity_type:invoice|work_report}/{id:[0-9]+}/signature-selection', [SignatureDocumentSelectionAction::class, 'delete']);

        // Web faktura — správa trvalého veřejného odkazu (authenticated)
        $app->post   ('/api/invoices/{id:[0-9]+}/public-link',            CoreInvoiceWriteGoneAction::class);
        $app->post   ('/api/invoices/{id:[0-9]+}/public-link/regenerate', CoreInvoiceWriteGoneAction::class);

        // Web faktura — veřejný náhled + PDF + přílohy (bez auth, jen token)
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}',     PublicInvoiceGetAction::class);
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}/pdf', PublicInvoicePdfAction::class);
        $app->get    ('/api/public/invoice/{token:[a-f0-9]{32,128}}/attachment/{attId:[0-9]+}', PublicInvoiceAttachmentAction::class);

        // Admin (M6)
        $app->get    ('/api/admin/activity-log',    ListActivityLogAction::class);
        $app->get    ('/api/admin/sent-emails',     ListSentEmailsAction::class);
        $app->get    ('/api/admin/smtp-log-analysis', \MyInvoice\Action\Admin\SmtpLogAnalysisAction::class);
        $app->get    ('/api/admin/smtp-log-analysis/status', [\MyInvoice\Action\Admin\InvoiceSmtpLogAction::class, 'status']);
        $app->get    ('/api/admin/invoices/{id:[0-9]+}/smtp-log', [\MyInvoice\Action\Admin\InvoiceSmtpLogAction::class, 'forInvoice']);
        $app->get    ('/api/admin/cron-jobs',       CronJobsAction::class);
        $app->post   ('/api/admin/cron-jobs/{script:cron-[a-z0-9-]+}/run', RunCronJobAction::class);
        $app->get    ('/api/admin/invoices-zip',    InvoicesZipAction::class);  // legacy — drží se kvůli historickým bookmark URL
        $app->get    ('/api/admin/export',          ExportAction::class);       // generic export (?format=pdf-zip|isdoc|pohoda|stereo&month=YYYY-MM nebo period=quarterly)
        $app->get    ('/api/admin/users',           [UserAdminAction::class, 'list']);
        $app->post   ('/api/admin/users',           [UserAdminAction::class, 'create']);
        $app->put    ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'update']);
        $app->delete ('/api/admin/users/{id:[0-9]+}', [UserAdminAction::class, 'delete']);
        // Membership uživatel ↔ supplier (jemný tenant přístup, migrace 0148)
        $app->get    ('/api/admin/users/{id:[0-9]+}/suppliers', [\MyInvoice\Action\Admin\UserSupplierAdminAction::class, 'list']);
        $app->put    ('/api/admin/users/{id:[0-9]+}/suppliers', [\MyInvoice\Action\Admin\UserSupplierAdminAction::class, 'replace']);

        // Approval inbox (admin only) — globální seznam schvalování

        // Email šablony (admin only)
        $app->get    ('/api/admin/email-templates',                                  [EmailTemplateAction::class, 'list']);
        $app->get    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'get']);
        $app->put    ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'put']);
        $app->delete ('/api/admin/email-templates/{code:[a-z_]+}/{locale:cs|en}',    [EmailTemplateAction::class, 'delete']);

        // Multi-supplier (M7)
        $app->get    ('/api/suppliers',                     [SettingsAction::class, 'listSuppliers']);
        $app->post   ('/api/suppliers',                     [SettingsAction::class, 'createSupplier']);
        $app->get    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'getSupplierById']);
        $app->put    ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'updateSupplierById']);
        $app->delete ('/api/suppliers/{id:[0-9]+}',         [SettingsAction::class, 'deleteSupplierById']);

        // Settings (M6) — aktuální supplier (z X-Supplier-Id)
        $app->get ('/api/settings/supplier',                [SettingsAction::class, 'getSupplier']);
        $app->put ('/api/settings/supplier',                [SettingsAction::class, 'updateSupplier']);
        $app->put ('/api/settings/supplier/invoice-counter', SupplierInvoiceCounterAction::class);
        $app->get ('/api/settings/nace-codes',              NaceCodesAction::class);
        $app->get    ('/api/settings/email-profiles',       [EmailProfilesAction::class, 'list']);
        $app->post   ('/api/settings/email-profiles',       [EmailProfilesAction::class, 'create']);
        $app->post   ('/api/settings/email-profiles/test',  [EmailProfilesAction::class, 'testDraft']);
        $app->post   ('/api/settings/email-profiles/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/email-profiles/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/test', [EmailProfilesAction::class, 'test']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/imap-test', [EmailProfilesAction::class, 'testImapSettings']);
        $app->post   ('/api/settings/email-profiles/{id:[0-9]+}/folders', [EmailProfilesAction::class, 'browseImapFolders']);
        $app->put    ('/api/settings/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'update']);
        $app->delete ('/api/settings/email-profiles/{id:[0-9]+}', [EmailProfilesAction::class, 'delete']);
        $app->get    ('/api/settings/branding-profiles',                 [BrandingProfilesAction::class, 'list']);
        $app->post   ('/api/settings/branding-profiles',                 [BrandingProfilesAction::class, 'create']);
        $app->put    ('/api/settings/branding-profiles/{id:[0-9]+}',     [BrandingProfilesAction::class, 'update']);
        $app->delete ('/api/settings/branding-profiles/{id:[0-9]+}',     [BrandingProfilesAction::class, 'delete']);
        $app->post   ('/api/settings/branding-profiles/{id:[0-9]+}/default', [BrandingProfilesAction::class, 'setDefault']);
        $app->post   ('/api/settings/branding-profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/branding-profiles/{id:[0-9]+}/logo', [BrandingProfilesAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/pdf-signing/diagnostics', PdfSigningDiagnosticsAction::class);
        $app->get    ('/api/settings/pdf-signing',          [SigningProfilesAction::class, 'pdfSettings']);
        $app->post   ('/api/settings/pdf-signing/test',     [SigningProfilesAction::class, 'testPdfSigning']);
        $app->put    ('/api/settings/pdf-signing/output-settings/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updatePdfOutputSetting']);
        $app->get    ('/api/settings/pdf-signing/user-defaults', [SigningProfilesAction::class, 'userDefaults']);
        $app->put    ('/api/settings/pdf-signing/user-defaults/{output_type:[a-z_]+}', [SigningProfilesAction::class, 'updateUserDefault']);
        $app->get    ('/api/settings/signing',              [SigningProfilesAction::class, 'settings']);
        $app->put    ('/api/settings/signing',              [SigningProfilesAction::class, 'updateSettings']);
        $app->get    ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'listProfiles']);
        $app->post   ('/api/settings/signing/profiles',              [SigningProfilesAction::class, 'createProfile']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'credentialCertificate']);
        $app->post   ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'uploadCredentialCertificate']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'updateCredentialCertificate']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}/credentials/certificate', [SigningProfilesAction::class, 'deleteCredentialCertificate']);
        $app->get    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'getProfile']);
        $app->put    ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'updateProfile']);
        $app->delete ('/api/settings/signing/profiles/{id:[0-9]+}', [SigningProfilesAction::class, 'deleteProfile']);
        $app->get    ('/api/settings/currencies',                     [SettingsAction::class, 'listCurrencies']);
        $app->post   ('/api/settings/currencies',                     [SettingsAction::class, 'createCurrency']);
        $app->put    ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'updateCurrency']);
        $app->delete ('/api/settings/currencies/{id:[0-9]+}',         [SettingsAction::class, 'deleteCurrency']);

        $app->get    ('/api/settings/vat-rates',                      [SettingsAction::class, 'listVatRates']);
        $app->post   ('/api/settings/vat-rates',                      [SettingsAction::class, 'createVatRate']);
        $app->put    ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'updateVatRate']);
        $app->delete ('/api/settings/vat-rates/{id:[0-9]+}',          [SettingsAction::class, 'deleteVatRate']);

        $app->get    ('/api/settings/countries',                      [SettingsAction::class, 'listCountries']);
        $app->post   ('/api/settings/countries',                      [SettingsAction::class, 'createCountry']);
        $app->put    ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'updateCountry']);
        $app->delete ('/api/settings/countries/{id:[0-9]+}',          [SettingsAction::class, 'deleteCountry']);

        // Email branding (M16) — per-supplier logo + accent color v hlavičce odchozích emailů
        $app->post   ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/email-branding/logo',            [EmailBrandingAction::class, 'deleteLogo']);
        $app->get    ('/api/settings/email-branding/preview',         [EmailBrandingAction::class, 'preview']);
        // Veřejné API aliasy pro logo (bearer allowlist) — stejná logika, jiná cesta.
        // Preview zůstává interní (čte soubory z disku → jen session admin).
        $app->post   ('/api/settings/supplier/logo',                  [EmailBrandingAction::class, 'uploadLogo']);
        $app->delete ('/api/settings/supplier/logo',                  [EmailBrandingAction::class, 'deleteLogo']);

        $app->get    ('/api/settings/units',                          [SettingsAction::class, 'listUnits']);
        $app->post   ('/api/settings/units',                          [SettingsAction::class, 'createUnit']);
        $app->put    ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'updateUnit']);
        $app->delete ('/api/settings/units/{id:[0-9]+}',              [SettingsAction::class, 'deleteUnit']);
        $app->get    ('/api/settings/tri-sidebar',                    [TriSidebarSettingsAction::class, 'get']);
        $app->put    ('/api/settings/tri-sidebar',                    [TriSidebarSettingsAction::class, 'put']);

        \MyInvoice\Tri\TriRoutes::register($app);

        // 404 fallback pro /api/*
        $app->any('/api/{path:.*}', function ($req, $res) {
            return \MyInvoice\Http\Json::error($res, 'not_found', 'Route not found', 404);
        });
    }
}
