<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class SyncJobDetailResource extends SyncJobResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'direction' => $this->direction,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'total_items' => $this->total_items,
            'processed_items' => $this->processed_items,
            'failed_items' => $this->failed_items,
            'error' => $this->last_error,
            'logs' => $this->logs ?? [],
            'result' => $this->result,
        ];
    }
}
