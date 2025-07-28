<?php

namespace App\Service;

use App\Model\DocumentationImpact;
use App\Model\MergeRequest;
use Symfony\Component\HttpFoundation\Request;

interface GitProviderInterface
{
    public function validateWebhook(Request $request): bool;

    public function parseMergeRequest(Request $request): MergeRequest;

    public function fetchMergeRequestDiff(MergeRequest $mr): string;

    public function postComment(MergeRequest $mr, DocumentationImpact $impact): void;

    public function getMergeRequestDetails(MergeRequest $mr): MergeRequest;
}