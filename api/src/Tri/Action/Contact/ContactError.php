<?php

declare(strict_types=1);

namespace MyInvoice\Tri\Action\Contact;

use MyInvoice\Http\Json;
use MyInvoice\Tri\MyUcto\MyUctoApiException;
use Psr\Http\Message\ResponseInterface as Response;

final class ContactError
{
    public static function fromException(Response $response, MyUctoApiException $e): Response
    {
        $status = $e->httpStatus >= 400 ? $e->httpStatus : 502;
        if ($e->errorCode === 'network') {
            $status = 503;
        }
        $extra = [];
        if ($e->details !== []) {
            $extra['fields'] = $e->details;
        }

        return Json::error($response, $e->errorCode, $e->getMessage(), $status, $extra);
    }
}
