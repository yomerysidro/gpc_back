<?php

namespace App\Services\ActivityRequests;

use App\Http\Data\ActivityRequests\ActivityRequestData;

/**
 * Compatibility facade for callers that already resolve the activity-request workflow.
 * Persistence, validation, transactions, and transitions are implemented in ActivityRequestData.
 */
class ActivityRequestWorkflow
{
    /** @param array<string, mixed> $input */
    public function createDraft(int $userId, array $input): array
    {
        return ActivityRequestData::createDraft($userId, $input);
    }

    /** @param array<string, mixed> $input */
    public function updateDraft(int $userId, int $requestId, array $input): array
    {
        return ActivityRequestData::updateDraft($userId, $requestId, $input);
    }

    public function submit(int $userId, int $requestId): array
    {
        return ActivityRequestData::submit($userId, $requestId);
    }

    /** @param array<string, mixed> $filters */
    public function ownRequests(int $userId, array $filters = []): array
    {
        return ActivityRequestData::ownRequests($userId, $filters);
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters = [], ?int $ownerId = null): array
    {
        return ActivityRequestData::list($filters, $ownerId);
    }

    public function detail(int $userId, int $requestId): array
    {
        return ActivityRequestData::detail($userId, $requestId);
    }

    public function reviewDetail(int $requestId): array
    {
        return ActivityRequestData::reviewDetail($requestId);
    }

    public function history(int $requestId, ?int $ownerId = null): array
    {
        return ActivityRequestData::history($requestId, $ownerId);
    }

    /** @param array<string, mixed> $filters */
    public function decisionHistory(array $filters = []): array
    {
        return ActivityRequestData::decisionHistory($filters);
    }

    public function decide(int $responsibleUserId, int $requestId, string $decision, ?string $observation = null): array
    {
        return ActivityRequestData::decide($responsibleUserId, $requestId, $decision, $observation);
    }
}
