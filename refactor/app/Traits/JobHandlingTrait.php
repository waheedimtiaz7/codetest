<?php

namespace DTApi\Traits;

use Carbon\Carbon;
use App\Helpers\TeHelper;

trait JobHandlingTrait
{
    protected function handleImmediateJob(array $data, $immediatetime)
    {
        $dueCarbon = Carbon::now()->addMinutes($immediatetime);
        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
        $data['customer_phone_type'] = 'yes';
        return $data;
    }

    protected function handleRegularJob(array $data)
    {
        $due = $data['due_date'] . " " . $data['due_time'];
        $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');

        if ($dueCarbon->isPast()) {
            return [
                'status' => 'fail',
                'message' => "Can't create booking in the past",
            ];
        }

        return $data;
    }

    protected function processJobAttributes(array $data, $user)
    {
        // Setting gender and certification based on 'job_for' input
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } elseif (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }

        // Certification logic
        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = 'normal';
        }
        if (in_array('certified', $data['job_for'])) {
            $data['certified'] = 'yes';
        }
        if (in_array('certified_in_law', $data['job_for'])) {
            $data['certified'] = 'law';
        }
        if (in_array('certified_in_health', $data['job_for'])) {
            $data['certified'] = 'health';
        }

        // Handling composite types like 'normal' and 'certified' together
        $data = $this->handleCompositeCertificationTypes($data);

        // Setting job type based on user's consumer type
        $consumerType = $user->userMeta->consumer_type ?? null;
        $data['job_type'] = $this->mapConsumerTypeToJobType($consumerType);

        // Setting additional attributes
        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        if (isset($data['due'])) {
            $data['will_expire_at'] = \DTApi\Helpers\TeHelper::willExpireAt($data['due'], $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        return $data;
    }

    protected function handleCompositeCertificationTypes(array $data)
    {
        // This method simplifies handling of composite certification types
        $certificationTypes = ['certified', 'certified_in_law', 'certified_in_health'];
        $intersect = array_intersect($certificationTypes, $data['job_for']);

        if (count($intersect) > 1) {
            $data['certified'] = 'both';
        }

        return $data;
    }

    protected function mapConsumerTypeToJobType(?string $consumerType)
    {
        // Maps consumer type to job type
        return match ($consumerType) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
            default => 'unknown', // Adjust the default type as needed
        };
    }

    protected function extractJobData($data, $job)
    {
        $fieldsToUpdate = ['user_email', 'reference', 'address', 'instructions', 'town'];
        foreach ($fieldsToUpdate as $field) {
            if (!empty($data[$field])) {
                $job->$field = $data[$field];
            } elseif ($field === 'address' || $field === 'instructions' || $field === 'town') {
                $userMetaFallback = $job->user()->first()->userMeta->$field ?? '';
                $job->$field = $userMetaFallback;
            }
        }
        return $job->getAttributes();
    }

    public function jobToData($job)
    {
        $dueParts = explode(" ", $job->due);
        ////Flatmap used to make in an array
        $jobFor = collect([$job->gender, $job->certified])
            ->flatMap(function ($item) {
                switch ($item) {
                    case 'male': return ['Man'];
                    case 'female': return ['Kvinna'];
                    case 'both': return ['Godkänd tolk', 'Auktoriserad'];
                    case 'yes': return ['Auktoriserad'];
                    case 'n_health': return ['Sjukvårdstolk'];
                    case 'law':
                    case 'n_law': return ['Rättstolk'];
                    default: return [$item];
                }
            })->filter()->values()->all();

        return [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type ?? '',
            'due_date' => $dueParts[0] ?? '',
            'due_time' => $dueParts[1] ?? '',
            'job_for' => $jobFor,
        ];
    }

    protected function determineJobType($translator_type)
    {
        return match($translator_type) {
            'professional' => 'paid',
            'rwstranslator' => 'rws',
            default => 'unpaid',
        };
    }

    protected function mapJobTypeToTranslatorType($job_type)
    {
        return match ($job_type) {
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
            default => null,
        };
    }
    protected function isJobSuitableForUser($job, $user_id)
    {
        $checktown = Job::checkTowns($job->user_id, $user_id);
        return !(($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && !$checktown);
    }

    protected function determineTranslatorLevels($certification)
    {
        $levels = [];

        if (in_array($certification, ['yes', 'both'])) {
            $levels = array_merge($levels, [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care'
            ]);
        }

        if (in_array($certification, ['law', 'n_law'])) {
            $levels[] = 'Certified with specialisation in law';
        }

        if (in_array($certification, ['health', 'n_health'])) {
            $levels[] = 'Certified with specialisation in health care';
        }

        if ($certification === 'normal' || $certification === 'both') {
            $levels = array_merge($levels, ['Layman', 'Read Translation courses']);
        }

        if ($certification === null) {
            $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
        }

        // Using array_unique to eliminate any duplicate levels that may have been added in the conditions.
        return array_unique($levels);
    }

    protected function formatSessionTime($session_time) {
        $diff = explode(':', $session_time);
        return $diff[0] . ' tim ' . $diff[1] . ' min';
    }

    protected function calculateSessionTime($dueDate, $completedDate)
    {
        $start = new \DateTime($dueDate);
        $end = new \DateTime($completedDate);
        $interval = $end->diff($start);
        return $interval->format('%H:%I:%S');
    }
}
