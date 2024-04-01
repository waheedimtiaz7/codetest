<?php

namespace DTApi\Traits;

trait ResponseTrait
{
    protected function generateResponse($status, $message, $data = null, $additionalData = [])
    {
        $response = [
            'status' => $status,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json(array_merge($response, $additionalData));
    }
}
