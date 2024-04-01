<?php

namespace DTApi\Traits;

use App\Job;
use App\User;

trait UserJobsTrait
{
    private function getJobs($userId, $history = false, $page = 1)
    {
        $user = User::find($userId);
        if (!$user) {
            return $this->formatResponse();
        }

        $userType = $user->is('customer') ? 'customer' : 'translator';
        $jobsQuery = $this->jobsQuery($user, $userType, $history, $page);

        // For job history of translators which doesn't use the paginate method directly
        $numPages = $history && $userType == 'translator' ? ceil($jobsQuery->count() / 15) : ($jobsQuery->lastPage() ?? 0);

        return $this->formatResponse($jobsQuery, $user, $userType, $numPages, $page, $history);
    }

    private function jobsQuery($user, $userType, $history, $page)
    {
        if ($history) {
            if ($userType === 'customer') {
                return $user->jobs()
                    ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance'])
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15, ['*'], 'page', $page);
            } else {
                // I am assuming it is returning query builder instance
                return Job::getTranslatorJobsHistoric($user->id, 'historic', $page)
                    ->paginate(15, ['*'], 'page', $page);
            }
        } else {
            // Current jobs logic
            $jobsQuery = $userType === 'customer' ?
                $user->jobs()->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback']) :
                Job::getTranslatorJobs($user->id, 'new')->pluck('jobs');

            return $jobsQuery->when($userType === 'customer', function ($query) {
                return $query->whereIn('status', ['pending', 'assigned', 'started']);
            })->get()->groupBy(function ($job) {
                return $job->immediate == 'yes' ? 'emergencyJobs' : 'normalJobs';
            });
        }
    }

    private function formatResponse($jobs = null, $user = null, $userType = '', $numPages = 0, $page = 1, $history = false)
    {
        if ($history || $userType === 'translator') {
            // History or translator jobs, treated as normal jobs
            return [
                'emergencyJobs' => [],
                'noramlJobs' => $history ? $jobs->items() : [],
                'jobs' => $jobs,
                'user' => $user,
                'usertype' => $userType,
                'numpages' => $numPages,
                'pagenum' => $page,
            ];
        }

        // Customer's current jobs
        $normalJobs = $jobs['normalJobs'] ?? collect([]);
        $emergencyJobs = $jobs['emergencyJobs'] ?? [];

        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $normalJobs,
            'cuser' => $user,
            'usertype' => $userType,
            'numpages' => 0,
            'pagenum' => 1
        ];
    }

    protected function applyJobFilters($query, $requestData, $isSuperAdmin = false)
    {
        $query->when(isset($requestData['id']) && $requestData['id'] !== '', function ($q) use ($requestData) {
            is_array($requestData['id']) ? $q->whereIn('id', $requestData['id']) : $q->where('id', $requestData['id']);
        })
            ->when(isset($requestData['lang']) && $requestData['lang'] !== '', function ($q) use ($requestData) {
                $q->whereIn('from_language_id', $requestData['lang']);
            })
            ->when(isset($requestData['status']) && $requestData['status'] !== '', function ($q) use ($requestData) {
                $q->whereIn('status', $requestData['status']);
            })
            ->when(isset($requestData['feedback']) && $requestData['feedback'] !== 'false', function ($q) {
                $q->where('ignore_feedback', '0')
                    ->whereHas('feedback', function ($subQuery) {
                        $subQuery->where('rating', '<=', '3');
                    });
            })
            ->when(isset($requestData['filter_timetype']), function ($q) use ($requestData) {
                $columnName = $requestData['filter_timetype'] === "created" ? 'created_at' : 'due';
                $q->when(isset($requestData['from']) && $requestData['from'] !== '', function ($subQuery) use ($columnName, $requestData) {
                    $subQuery->where($columnName, '>=', $requestData['from']);
                })
                    ->when(isset($requestData['to']) && $requestData['to'] !== '', function ($subQuery) use ($columnName, $requestData) {
                        $to = $requestData['to'] . " 23:59:00";
                        $subQuery->where($columnName, '<=', $to);
                    })
                    ->orderBy($columnName, 'desc');
            })
            ->when($isSuperAdmin && isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] !== '', function ($q) use ($requestData) {
                $userIds = DB::table('users')->whereIn('email', $requestData['customer_email'])->pluck('id');
                $q->whereIn('user_id', $userIds);
            })
            ->when($isSuperAdmin && isset($requestData['translator_email']) && count($requestData['translator_email']), function ($q) use ($requestData) {
                $translatorIds = DB::table('users')->whereIn('email', $requestData['translator_email'])->pluck('id');
                $jobIds = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', $translatorIds)->pluck('job_id');
                $q->whereIn('id', $jobIds);
            })
            ->when($isSuperAdmin && isset($requestData['job_type']) && $requestData['job_type'] !== '', function ($q) use ($requestData) {
                $q->whereIn('job_type', $requestData['job_type']);
            })
            ->when($isSuperAdmin && isset($requestData['physical']), function ($q) use ($requestData) {
                $q->where('customer_physical_type', $requestData['physical'])
                    ->where('ignore_physical', 0);
            })
            ->when($isSuperAdmin && isset($requestData['phone']), function ($q) use ($requestData) {
                $q->where('customer_phone_type', $requestData['phone'])
                    ->when(isset($requestData['physical']), function ($subQuery) {
                        $subQuery->where('ignore_physical_phone', 0);
                    });
            })
            ->when($isSuperAdmin && isset($requestData['flagged']), function ($q) use ($requestData) {
                $q->where('flagged', $requestData['flagged'])
                    ->where('ignore_flagged', 0);
            })
            ->when($isSuperAdmin && isset($requestData['distance']) && $requestData['distance'] == 'empty', function ($q) {
                $q->whereDoesntHave('distance');
            })
            ->when($isSuperAdmin && isset($requestData['salary']) && $requestData['salary'] == 'yes', function ($q) {
                $q->whereDoesntHave('user.salaries');
            })
            ->when($isSuperAdmin && isset($requestData['consumer_type']) && $requestData['consumer_type'] !== '', function ($q) use ($requestData) {
                $q->whereHas('user.userMeta', function ($subQuery) use ($requestData) {
                    $subQuery->where('consumer_type', $requestData['consumer_type']);
                });
            })
            ->when($isSuperAdmin && isset($requestData['booking_type']), function ($q) use ($requestData) {
                if ($requestData['booking_type'] == 'physical') {
                    $q->where('customer_physical_type', 'yes');
                } elseif ($requestData['booking_type'] == 'phone') {
                    $q->where('customer_phone_type', 'yes');
                }
            });

        return $query;
    }

}
