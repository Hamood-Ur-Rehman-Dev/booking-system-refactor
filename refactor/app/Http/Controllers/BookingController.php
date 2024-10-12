<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

use Illuminate\Support\Facades\Log;
use App\Http\Requests\UpdateJobWhileEmailRequest;
use App\Http\Requests\JobActionRequest;
use App\Http\Requests\DistanceFeedRequest;

// Improvements
// - Error handling with try-catch blocks and logging errors with Log class
// - Used Laravel's validation features for input validation.
// - As this controller is handling API requests, so it better to always reture JSON responce
//    - so insted of return response(); use return response()->json();

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

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

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        try {
            $userId     = $request->get('user_id');
            $userType   = $request->__authenticatedUser->user_type;

            $response   = $userId
            ? $this->repository->getUsersJobs($user_id) 
            : ($this->isAdmin($userType) ? $this->repository->getAll($request) : []);

            return response()->json($response); //  it would be more appropriate to use this
        }catch (\Exception $e) {
            Log::error('Error in index method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while fetching jobs.')], 500);
        }
    }

    /**
     * @param string $request
     * @return bool
     */
    private function isAdmin($userType): bool
    {
        // Suggestion: Would Be Better If Use Auth::user()->hasRole('admin') - Spatie/RolePermission Package
        return in_array($userType, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')]);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        try {
            $job = $this->repository->with('translatorJobRel.user')->find($id);
            return response()->json($job);
        } catch (\Exception $e) {
            Log::error('Error in show method: ' . $e->getMessage());
            return response()->json(['error' => __('Job not found.')], 404);
        }
    }

    /**
     * @param BookingRequest $request
     * @return mixed
     */
    public function store(BookingRequest $request)
    {
        // Comments: Although method is using BookingRequest but still $request->all() use $request->validated() instead
        try {
            $data       = $request->validated();
            $response   = $this->repository->store($request->__authenticatedUser, $data);
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in store method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while creating the job.')], 500);
        }

    }

    /**
     * @param $id
     * @param BookingRequest $request
     * @return mixed
     */
    public function update($id, BookingRequest $request)
    {
        // Comments: Similar to store try using BookingRequest for consistance and do validate inputs.
        try {
            $data       = $request->except(['_token', 'submit']);
            $cuser      = $request->__authenticatedUser;
            $response   = $this->repository->updateJob($id, $data, $cuser);
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in update method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while updating the job.')], 500);
        }
    }

    /**
     * @param UpdateJobWhileEmailRequest $request
     * @return mixed
     */
    public function immediateJobEmail(UpdateJobWhileEmailRequest $request)
    {
        try {
            $adminSenderEmail   = config('app.adminemail');
            $data               = $request->validated();
            $response           = $this->repository->storeJobEmail($data);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in immediateJobEmail method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while sending job email.')], 500);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        try{
            $user_id  = $request->get('user_id');
            $response = $user_id
                ? $this->repository->getUsersJobsHistory($user_id, $request)
                : null;
        } catch (\Exception $e) {
            Log::error('Error in getHistory method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while fetching job history.')], 500);
        }
    }

    /**
     * @param JobActionRequest $request
     * @return mixed
     */
    public function acceptJob(JobActionRequest $request)
    {
        try {
            $data       = $request->validated();
            $user       = $request->__authenticatedUser;
            $response   = $this->repository->acceptJob($data, $user);
    
            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in getHistory method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while accepting job.')], 500);
        }
    }

    /**
     * @param JobActionRequest $request
     * @return mixed
     */
    public function acceptJobWithId(JobActionRequest $request)
    {
        try {
            $data       = $request->get('job_id');
            $user       = $request->__authenticatedUser;
            $response   = $this->repository->acceptJobWithId($data, $user);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in acceptJobWithId method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while accepting job.')], 500);
        }
    }

    /**
     * @param JobActionRequest $request
     * @return mixed
     */
    public function cancelJob(JobActionRequest $request)
    {
        try {
            $data       = $request->validated();
            $user       = $request->__authenticatedUser;
            $response   = $this->repository->cancelJobAjax($data, $user);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in cancelJob method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while canceling job.')], 500);
        }
    }

    /**
     * @param JobActionRequest $request
     * @return mixed
     */
    public function endJob(JobActionRequest $request)
    {
        try {
            $data     = $request->validated();
            $response = $this->repository->endJob($data);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in endJob method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while ending job.')], 500);
        }

    }

    /**
     * @param JobActionRequest $request
     * @return mixed
     */
    public function customerNotCall(JobActionRequest $request)
    {
        try {
            $data     = $request->validated();
            $response = $this->repository->customerNotCall($data);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in customerNotCall method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while updating job completion status.')], 500);
        }

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        try {
            $data = $request->all(); // <-- Why this is HERE?
            $user = $request->__authenticatedUser;

            $response = $this->repository->getPotentialJobs($user);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in getPotentialJobs method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while fetching potential jobs.')], 500);
        }
    }

    /**
     * @param DistanceFeedRequest $request
     * @return mixed
     */
    public function distanceFeed(DistanceFeedRequest $request)
    {
        try{
            $data = $request->validated();
    
            $this->updateDistanceAndTime($data);
            $this->updateJobDetails($data);
    
            return response()->json('Record updated!');
        } catch (\Exception $e) {
            Log::error('Error in distanceFeed method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while updating job.')], 500);
        }
    }

    private function updateDistanceAndTime(array $data)
    {
        if (!empty($data['distance']) || !empty($data['time'])) {
            Distance::where('job_id', $data['jobid'])->update([
                'distance'  => $data['distance'] ?? '',
                'time'      => $data['time']     ?? ''
            ]);
        }
    }

    private function updateJobDetails(array $data)
    {
        $jobDetails = [
            'admin_comments'    => $data['admincomment'] ?? '',
            'session_time'      => $data['session_time'] ?? '',
            'manually_handled'  => $data['manually_handled'] ? 'yes' : 'no',
            'flagged'           => $data['flagged']  ? 'yes' : 'no',
            'by_admin'          => $data['by_admin'] ? 'yes' : 'no'
        ];

        Job::where('id', $data['jobid'])->update($jobDetails);
    }

    /**
     * @param ActionRequest $request
     * @return mixed
     */
    public function reopen(ActionRequest $request)
    {
        try {
            $data     = $request->validated();
            $response = $this->repository->reopen($data);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Error in reopen method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while reopening job.')], 500);
        }
    }

    public function resendNotifications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'jobid'     => 'required|jobs,id'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'error' => $errors
            ], 400);
        }

        // Comments: Maybe Validation Is Already Taken Care Of On Front End, But Is Good To Have Backend Validation
        try{
            $data     = $request->all();
            $job      = $this->repository->find($data['jobid']);
            $job_data = $this->repository->jobToData($job);
            $this->repository->sendNotificationTranslator($job, $job_data, '*');
    
            return response()->json(['success' => __('Push sent')]);
        } catch (\Exception $e) {
            Log::error('Error in reopen method: ' . $e->getMessage());
            return response()->json(['error' => __('An error occurred while sending notification.')], 500);
        }
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'jobid'     => 'required|jobs,id'
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            return response()->json([
                'error' => $errors
            ], 400);
        }

        
        try {
            $data     = $request->validaed();
            $job      = $this->repository->find($data['jobid']);
            $job_data = $this->repository->jobToData($job);

            $this->repository->sendSMSNotificationToTranslator($job);
            return response()->json(['success' => __('SMS sent')]);
        } catch (\Exception $e) {
            return response()->json(['success' => $e->getMessage()]);
        }
    }

}
