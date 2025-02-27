<?php

namespace DTApi\Http\Controllers;

use App\Http\Requests\StoreJobRequest;
use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use DTApi\Traits\ResponseTrait;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    use ResponseTrait;
    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    protected function isAdmin($user)
    {
        return in_array($user->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')]);
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {

        if ($userId = $request->get('user_id')) {
            $data = $this->repository->getUsersJobs($userId);
            return $this->generateResponse(true, 'User jobs fetched successfully.', $data);
        } elseif ($this->isAdmin($request->__authenticatedUser)) {
            $data = $this->repository->getAll($request);
            return $this->generateResponse(true, 'All jobs fetched successfully for admin.', $data);
        }

        return $this->generateResponse(false, 'No valid action found.', null);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(StoreJobRequest $request)
    {
        $user = auth()->user(); // or any method to get the current user

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return $this->generateResponse('fail', 'Translator cannot create booking');
        }

        // Proceed with storing the job using the validated data
        $data = $request->validated();

        $response = $this->repository->store(auth()->user(), $data);

        return $this->generateResponse('success', 'Job created successfully', $response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $request->__authenticatedUser;
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        if($user_id = $request->get('user_id')) {

            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            return response($response);
        }

        return null;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $request->__authenticatedUser;

        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        $response = $this->repository->customerNotCall($data);

        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        $validated = $request->validate([
            'distance' => 'sometimes|string',
            'time' => 'sometimes|string',
            'jobid' => 'required|exists:jobs,id',
            'session_time' => 'sometimes|string',
            'flagged' => 'sometimes|boolean',
            'manually_handled' => 'sometimes|boolean',
            'by_admin' => 'sometimes|boolean',
            'admincomment' => 'required_if:flagged,true|string',
        ]);

        // Update Distance if applicable
        if ($validated['distance'] || $validated['time']) {
            Distance::updateOrCreate(
                ['job_id' => $validated['jobid']],
                ['distance' => $validated['distance'] ?? '', 'time' => $validated['time'] ?? '']
            );
        }

        // Update Job with relevant fields
        Job::where('id', $validated['jobid'])->update([
            'admin_comments' => $validated['admincomment'] ?? '',
            'flagged' => $request->boolean('flagged') ? 'yes' : 'no',
            'session_time' => $validated['session_time'] ?? '',
            'manually_handled' => $request->boolean('manually_handled') ? 'yes' : 'no',
            'by_admin' => $request->boolean('by_admin') ? 'yes' : 'no',
        ]);

        return $this->generateResponse('success', 'Job updated successfully',);
    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return response($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

}
