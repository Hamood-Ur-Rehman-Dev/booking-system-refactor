<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

// Remarks:
// - Better to extract, Notifications, SMS, Emails etc. to their own services to reduce code size and maintainability
// - Try to use early exits if possible
// - Make use of Carbon for date manupulation if included
// - Set a default values if not set
// - Extracted the sendEmail() so that it can be re-used
// - Instead of using constant string, try using constants like Job::STATUS_PENDING, Job::STATUS_ASSIGNED, Job::STATUS_STARTED, Job::STATUS_COMPLETED, etc.;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        // Remarks: The Job::checkParticularJob() method is called for each normal job, 
        // which could lead to performance issues if there are many jobs. 
        // So, Eager loading the data can be used instead. 

        $cuser          = User::find($user_id);
        $usertype       = '';
        $emergencyJobs  = [];
        $noramlJobs     = [];

        if($cuser){
            if ($cuser->is('customer')) {
                $usertype = 'customer';
                $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
            } elseif ($cuser->is('translator')) {
                $usertype = 'translator';
                $jobs = Job::getTranslatorJobs($cuser->id, 'new');
                $jobs = $jobs->pluck('jobs')->all();
            }

            // Comment: What to do if $cuser is not 'customer' or 'translator'?
            //          should have 'else' block

            if ($jobs) {
                foreach ($jobs as $jobitem) {
                    if ($jobitem->immediate == 'yes') {
                        $emergencyJobs[] = $jobitem;
                    } else {
                        $noramlJobs[] = $jobitem;
                    }
                }
                $noramlJobs = collect($noramlJobs)->each(function ($item, $key) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob($user_id, $item);
                })->sortBy('due')->all();
            }
        }

        return [
            'emergencyJobs' => $emergencyJobs, 
            'noramlJobs'    => $noramlJobs, 
            'cuser'         => $cuser, 
            'usertype'      => $usertype
        ];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page    = $request->get('page');
        $pagenum = isset($page)? $page : "1";
        
        $cuser          = User::find($user_id);
        $usertype       = '';
        // $emergencyJobs  = []; // Not Using This Variable So Can Be Removed
        $noramlJobs     = [];
        $jobs           = [];

        if($cuser){
            if ($cuser->is('customer')) {
                $usertype = 'customer';
                $numpages = 0;
                $pagenum  = 0;
                $jobs     = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);
    
            } elseif ($cuser->is('translator')) {
                $jobs_ids   = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
                $totaljobs  = $jobs_ids->total();
                $numpages   = ceil($totaljobs / 15);
                $usertype   = 'translator';
                $jobs       = $jobs_ids;
                $noramlJobs = $jobs_ids;
            }
        }

        return [
            // 'emergencyJobs' => $emergencyJobs, 
            'noramlJobs'    => $noramlJobs, 
            'jobs'          => $jobs, 
            'cuser'         => $cuser, 
            'usertype'      => $usertype, 
            'numpages'      => $numpages, 
            'pagenum'       => $pagenum
        ];
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        if ($user->user_type == env('CUSTOMER_ROLE_ID')) {
            $cuser = $user;

            if (!isset($data['from_language_id'])) {
                $response['status']     = 'fail';
                $response['message']    = __("Du måste fylla in alla fält");
                $response['field_name'] = "from_language_id";
                return $response;
            }
            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = __("Du måste fylla in alla fält");
                    $response['field_name'] = "due_date";
                    return $response;
                }
                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = __("Du måste fylla in alla fält");
                    $response['field_name'] = "due_time";
                    return $response;
                }
                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status']     = 'fail';
                    $response['message']    = __("Du måste göra ett val här");
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = __("Du måste fylla in alla fält");
                    $response['field_name'] = "duration";
                    return $response;
                }
            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status']     = 'fail';
                    $response['message']    = __("Du måste fylla in alla fält");
                    $response['field_name'] = "duration";
                    return $response;
                }
            }
            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type']     = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type']     = 'no';
                $response['customer_physical_type'] = 'no';
            }

            if ($data['immediate'] == 'yes') {
                $due_carbon                  = Carbon::now()->addMinute($immediatetime);
                $data['due']                 = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate']           = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type']            = 'immediate';

            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type']   = 'regular';
                $due_carbon         = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due']        = $due_carbon->format('Y-m-d H:i:s');
                if ($due_carbon->isPast()) {
                    $response['status']  = 'fail';
                    $response['message'] = __("Can't create booking in past");
                    return $response;
                }
            }
            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }
            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            }
            else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }
            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for']))
            {
                $data['certified'] = 'n_law';
            }
            else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for']))
            {
                $data['certified'] = 'n_health';
            }
            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';
            $data['b_created_at'] = date('Y-m-d H:i:s');
            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();
            if ($job->gender != NULL) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }
            }
            if ($job->certified != NULL) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }
            }

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;

    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type       = $data['user_type'];
        $job             = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference  = isset($data['reference']) ? $data['reference'] : '';
        $user            = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address       = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions  = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town          = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $subject = __('Vi har mottagit er tolkbokning. Bokningsnr: #') . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type']   = $user_type;
        $response['job']    = $job;
        $response['status'] = 'success';
        $data               = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;

    }

    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        // Check if the job exists, Would Be Good In Case 
        if (!$job) {
            $this->logger->addInfo("Job object is null or invalid");
            return [];
        }

        // For Spliting DateTime We Can User Carbon Library instead of explode(" ", $job->due)
        // Is Much Easier & Readable
        $dueDateTime  = Carbon::parse($job->due);

        // This way off initilization makes the code cleaner and emphasizes the structure of the data
        $data = [
            'job_id'                    => $job->id,
            'from_language_id'          => $job->from_language_id,
            'immediate'                 => $job->immediate,
            'duration'                  => $job->duration,
            'statu'                     => $job->status,
            'gender'                    => $job->gender,
            'certified'                 => $job->certified,
            'due'                       => $job->due,
            'job_type'                  => $job->job_type,
            'customer_phone_type'       => $job->customer_phone_type,
            'customer_physical_type'    => $job->customer_physical_type,
            'customer_town'             => $job->town,
            'customer_type'             => $job->user->userMeta->customer_type,
            'due_date'                  => $dueDateTime->toDateString(),
            'due_time'                  => $dueDateTime->toTimeString(),
            'job_for'                   => $this->mapJobFor($job),
        ];

        return $data;

    }

    private function mapJobFor($job) {
        $jobFor = [];
        if ($job->gender) {
            $jobFor[] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }
        if ($job->certified) {
            $jobFor = array_merge($jobFor, $this->mapCertification($job->certified));
        }
        return $jobFor;
    }

    private function mapCertification($certified) {
        $certifications = [
            'both'      => ['Godkänd tolk', 'Auktoriserad'],
            'yes'       => ['Auktoriserad'],
            'n_health'  => ['Sjukvårdstolk'],
            'law'       => ['Rätttstolk'],
            'n_law'     => ['Rätttstolk']
        ];

        return $certifications[$certified] ?? [$certified];
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $completeddate      = Carbon::now(); 
        $jobid              = $post_data["job_id"];
        $job_detail         = Job::with('translatorJobRel')->find($jobid);

        // Add Some Logging For Better Debugging
        if (!$job_detail) {
            $this->logger->addInfo("Job detail doesnot exist for $jobid");
            return;
        }
        
        // Use Carbon Library Instead of explode(" ", $job->due)
        $duedate            = Carbon::parse($job_detail->due); // $duedate = $job_detail->due;
        $interval           = $duedate->diff($completeddate)->format('%H:%I:%S'); // $diff->h . ':' . $diff->i . ':' . $diff->s;

        $job                = $job_detail;
        $job->end_at        = $completeddate;
        $job->status        = 'completed';
        $job->session_time  = $interval;
        $job->save();

        // User Separate Function To Handle Email
        $user         = $job->user;
        $session_time = $duedate->diffForHumans($completeddate);
        $this->sendJobEndEmail($user, $job, $session_time, 'faktura');

        $tr = $job->translatorJobRel
        ->whereNULL('completed_at')
        ->whereNULL('cancel_at')
        ->first();

        if (!$tr) {
            $this->logger->addInfo("Translator detail doesnot exist for $jobid");
            return;
        }

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();

        // User Separate Function To Handle Email
        $this->sendJobEndEmail($tr->user, $job, $session_time, 'lön');

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));
        // As per new Laravel event can directly called using event()
        // event(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id));
    }

    private function sendJobEndEmail($user, $job, $session_time, $for_text)
    {
        $email      = $user->email;
        $name       = $user->name;
        $subject    = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data       = [
            'user'          => $user,
            'job'           => $job,
            'session_time'  => $session_time,
            'for_text'      => $for_text
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        if (!$user_meta) {
            $this->logger->addInfo("No meta details for user $user_id");
            return []; 
        }

        $job_type  = $this->getUserJobType($user_meta->translator_type);
        $languages = UserLanguages::where('user_id', $user_id)
        ->pluck('lang_id')
        ->toArray();

        if (empty($languages)) {
            $this->logger->addInfo("No language associated with user $user_id");
            return [];
        }

        // Instead of DB query inside for loop for each Job try 
        // extacting all jobs and filtering them here
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', 
        $languages, $user_meta->gender, $user_meta->translator_level)
        ->filter(function ($job) use ($user_id) {
            // filter perform more complex checks without additional database calls
            return $this->isJobValidForTranslator($job, $user_id);
        })
        ->pluck('id')
        ->toArray();

        return TeHelper::convertJobIdsInObjs($job_ids);
    }

    private function getUserJobType($translator_type)
    {   
        // php8 match operator can be used for better readbility and maintainability
        // https://www.php.net/manual/en/control-structures.match.php
        return match ($translator_type) {
            'professional'   => 'paid',
            'rwstranslator'  => 'rws',
            default          => 'unpaid', // All unpaid jobs can be handled with default i.e volunteer
        };
    }

    private function isJobValidForTranslator($job, $user_id)
    {
        // Check if the job requires a physical presence 
        // and if the translator is in the same town.
        $checkTown = Job::checkTowns($job->user_id, $user_id);
        return !($job->customer_phone_type == 'no' || empty($job->customer_phone_type)) 
            && $job->customer_physical_type == 'yes' 
            && $checkTown;
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        // Rather than extracting all users and looping through 
        // try fetching only requird users and send notification to them
        // assuming from following: user is translator and he is not disabled
        $users = User::where('user_type', '2') // 2 => Translators (assuming)
            ->where('status', '1')   // 1 => Active (assuming)
            ->where('id', '!=', $exclude_user_id)
            ->get();

        $translator_array        = [];  // suitable translators (no need to delay push)
        $delpay_translator_array = [];  // suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            // Combine if statments and early exit the loop if possible
            if (!$this->isNeedToSendPush($oneUser->id) || 
                ($data['immediate'] == 'yes' && TeHelper::getUsermeta($oneUser->id, 'not_get_emergency') == 'yes')
            ) {
                continue;
            }

            $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user
            if ($jobs->contains('id', $job->id)) {
                $userId = $oneUser->id;
                $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);

                if ($job_for_translator == 'SpecificJob' && Job::checkParticularJob($userId, $job) != 'userCanNotAcceptJob') {
                    if ($this->isNeedToDelayPush($userId)) {
                        $delpay_translator_array[] = $oneUser;
                    } else {
                        $translator_array[]        = $oneUser;
                    }
                }
            }
        }
        $data['language']           = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type']  = 'suitable_job';
        $msg_contents               = $data['immediate'] == 'no'
        ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
        : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        $msg_text                   = ["en" => $msg_contents];

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators    = $this->getPotentialTranslators($job);
        $jobPosterMeta  = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date       = date('d.m.Y', strtotime($job->due));
        $time       = date('H:i', strtotime($job->due));
        $duration   = $this->convertToHoursMins($job->duration);
        $jobId      = $job->id;
        $city       = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', [
            'date'      => $date, 
            'time'      => $time, 
            'duration'  => $duration, 
            'jobId'     => $jobId
        ]);

        $physicalJobMessageTemplate = trans('sms.physical_job', [
            'date'      => $date, 
            'time'      => $time, 
            'town'      => $city, 
            'duration'  => $duration, 
            'jobId'     => $jobId
        ]);

        // Determine if it's a phone or physical job
        $message = $job->customer_phone_type == 'yes' ? $phoneJobMessageTemplate : $physicalJobMessageTemplate;

        if (empty($message)) {
            Log::warning("Job #{$jobId} has an invalid configuration: both phone and physical types are set to 'no'.");
            return 0;
        }
        
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = $this->sendSMS($translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    protected function sendSMS($toNumber, $message)
    {
        // using config(app.sms_number) would be a better approach.
        $fromNumber = env('SMS_NUMBER');

        // Extracting will be good for future versions, and will improve overall reusablity
        return SendSMSHelper::send($fromNumber, $toNumber, $message);
    }

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        // User preference for receiving notifications at nighttime, default to 'no' if not set.
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime') ?? 'no';

        // Determine if it's nighttime and if the user has opted out of nighttime notifications.
        return DateTimeHelper::isNightTime() && $not_get_nighttime === 'yes';
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        // User preference for notifications, default to 'no' if not set.
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification') ?? 'no';

        // Return true if the user has not opted out of notifications.
        return $not_get_notification !== 'yes';
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        
        try{
            $onesignalAppID       = config('app.' . env('APP_ENV') . 'OnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.' . env('APP_ENV') . 'OnesignalApiKey'));

            $user_tags      = $this->getUserTagsStringFromArray($users);
            $data['job_id'] = $job_id;

            // Set notification sounds based on the job type
            $ios_sound = $android_sound = 'default';
            if ($data['notification_type'] == 'suitable_job') {
                $sound          = $data['immediate'] == 'no' ? 'normal_booking' : 'emergency_booking';
                $ios_sound      = "$sound.mp3";
                $android_sound  = $sound;
            }

            $fields = [
                'app_id'         => $onesignalAppID,
                'tags'           => json_decode($user_tags),
                'data'           => $data,
                'title'          => ['en' => 'DigitalTolk'],
                'contents'       => $msg_text,
                'ios_badgeType'  => 'Increase',
                'ios_badgeCount' => 1,
                'android_sound'  => $android_sound,
                'ios_sound'      => $ios_sound,
            ];

            if ($is_need_delay) {
                $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
            }

            $fields = json_encode($fields);

            // Remarks: Try extracting OneSignal to a saperate service for easy managment
            // Send the request using cURL
            $ch = curl_init("https://onesignal.com/api/v1/notifications");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json', 
                $onesignalRestAuthKey
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            if ($response === false) {
                $logger->error('cURL error: ' . curl_error($ch));
            } else {
                $logger->info('Push send response for job ' . $job_id, [$response]);
            }

            curl_close($ch);
        }catch (\Exception $e){
            $logger->error('Exception in sending push notification for job ' . $job_id . ': ' . $e->getMessage());
        }
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        // Determine the translator type based on the job type.
        $translator_type = match ($job->job_type) {
            'paid'      => 'professional',
            'rws'       => 'rwstranslator',
            'unpaid'    => 'volunteer',
            default     => null,
        };

        $jobLanguage = $job->from_language_id;
        $gender      = $job->gender;

        // Extract translator levels based on certifications
        $translator_level = [];
        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translator_level = [
                        'Certified',
                        'Certified with specialisation in law',
                        'Certified with specialisation in health care',
                    ];
                    break;
                case 'law':
                case 'n_law':
                    $translator_level = ['Certified with specialisation in law'];
                    break;
                case 'health':
                case 'n_health':
                    $translator_level = ['Certified with specialisation in health care'];
                    break;
                case 'normal':
                    $translator_level = [
                        'Layman',
                        'Read Translation courses',
                    ];
                    break;
            }
        }

        // If no specific certification is set, consider all levels.
        if (empty($translator_level)) {
            $translator_level = [
                'Certified',
                'Certified with specialisation in law',
                'Certified with specialisation in health care',
                'Layman',
                'Read Translation courses',
            ];
        }

        // Get black listed translators ids
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)
        ->pluck('translator_id')
        ->all();

        // Extract potential translators
        $users = User::getPotentialUsers(
            $translator_type,
            $jobLanguage,
            $gender,
            $translator_level,
            $blacklist
        );
        
        return $users;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job        = Job::find($id);
        $log_data   = [];

        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first()
        ?? $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time   = $job->due;
            $job->due   = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang               = $job->from_language_id;
            $job->from_language_id  = $data['from_language_id'];
            $langChanged            = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } 

        // Can be extracted for reusability and scalability
        $this->sendNotifications(
            $job, 
            $old_time ?? null, 
            $changeDue['dateChanged'], 
            $changeTranslator['translatorChanged'], 
            $langChanged, 
            $old_lang ?? null, 
            $current_translator, 
            $changeTranslator['new_translator']
        );    
    }

    private function sendNotifications($job, $old_time, $dateChanged, $translatorChanged, $langChanged, $old_lang, $current_translator, $new_translator)
    {
        if ($dateChanged) {
            $this->sendChangedDateNotification($job, $old_time);
        }
        if ($translatorChanged) {
            $this->sendChangedTranslatorNotification($job, $current_translator, $new_translator);
        }
        if ($langChanged) {
            $this->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $new_status = data_get($data, 'status'); //Safer way to access this value, Ref -> https://dev.to/tonyjoe/dataget-warning-with-array-keys-with-dots-laravel-tips-42ab
        
        if ($old_status !== $new_status) {
            $statusChanged = false;
            switch ($old_status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    // Add some warrning here if status is not recognized
                    Log::warning("Attempted to change job status from an unrecognized status: $old_status");
                return ['statusChanged' => false]; // No change for unrecognized status
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $new_status
                ];
                return [
                    'statusChanged' => true, 
                    'log_data'      => $log_data
                ];
            }
        }

        // Ensures the function always returns an array
        return ['statusChanged' => false];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $old_status     = $job->status;
        $job->status    = $data['status'];
        $user           = $job->user()->first();

        $email  = !empty($job->user_email) ? $job->user_email : $user->email;
        $name   = $user->name;
        
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at        = now();
            $job->emailsent         = 0;
            $job->emailsenttovirpal = 0;
            $job->save();

            $job_data   = $this->jobToData($job);
            $subject    = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;

            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } 
        
        if($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
//        }
//        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;

        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            if ($data['sesion_time'] == '') return false;
            
            $job->end_at        = now();
            $job->session_time  = $data['sesion_time'];
            $session_time       = $this->formatSessionTime($data['sesion_time']);

            // Send notification to the user
            $this->sendSessionEndedNotification($job, $session_time, 'faktura');

            // Send notification to the translator
            $this->sendTranslatorNotification($job, $session_time, 'lön');

        }
        $job->save();
        return true;
//        }
//        return false;
    }


    private function formatSessionTime($session_time)
    {
        // Using Carbon would be recommented but using old implementation here
        $diff = explode(':', $session_time);
        return "{$diff[0]} tim {$diff[1]} min"; // Formatted session time
    }

    private function sendSessionEndedNotification($job, $session_time, $for_text, $user = null)
    {
        $user   = $user? $user : $job->user()->first();
        $email  = $job->user_email ?: $user->email;
        $name   = $user->name;

        $dataEmail = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => $for_text,
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    private function sendTranslatorNotification($job, $session_time, $for_text)
    {
        $translator = $job->translatorJobRel->where('completed_at', NULL)->where('cancel_at', NULL)->first();
        $email = $translator->user->email;
        $name = $translator->user->name;

        $dataEmail = [
            'user'         => $translator,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => $for_text,
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        
        $job->admin_comments = $data['admin_comments'];
        $user                = $job->user()->first();

        $email               = !empty($job->user_email)? $job->user_email : $user->email;
        $name                = $user->name;
        $dataEmail           = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] === 'assigned' && $changedTranslator) {
            $this->handleAssignedStatus($job, $email, $name, $dataEmail);
        } else {
            $this->handleCancellation($job, $email, $name, $dataEmail);
        }
//        }
//        return false;
    }

    private function handleAssignedStatus($job, $email, $name, $dataEmail)
    {
        $job_data   = $this->jobToData($job);
        $subject    = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
        $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
    }

    private function handleCancellation($job, $email, $name, $dataEmail)
    {
        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->setupLogger();

        $data = [
            'notification_type' => 'session_start_remind',
        ];
        
        $msg_text = $this->createMessageText($job, $language, $due, $duration);

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationOfSession($user, $job, $data, $msg_text);
        }
    }

    private function setupLogger()
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    private function createMessageText($job, $language, $due, $duration)
    {
        $due_explode    = explode(' ', $due);
        $time           = $due_explode[1];
        $date           = $due_explode[0];

        if ($job->customer_physical_type == 'yes') {
            return [
                "en" => "Detta är en påminnelse om att du har en {$language} tolkning (på plats i {$job->town}) kl {$time} på {$date} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!"
            ];
        }

        return [
            "en" => "Detta är en påminnelse om att du har en {$language} tolkning (telefon) kl {$time} på {$date} som vara i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!"
        ];
    }
    private function sendPushNotificationOfSession($user, $job, $data, $msg_text)
    {
        $users_array = [$user];
        $this->bookingRepository->sendPushNotificationToSpecificUsers(
            $users_array,
            $job->id,
            $data,
            $msg_text,
            $this->bookingRepository->isNeedToDelayPush($user->id)
        );

        $this->logger->addInfo('sendSessionStartRemindNotification', ['job' => $job->id, 'user_id' => $user->id]);
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        // Avoid doing both validation and updation through single function
        if ($this->isValidStatusChange($data['status'])) {
            return $this->updateJobStatus($job, $data);
        }
        return false;

        // OLD

        // if (in_array($data['status'], ['timedout'])) {
        //     $job->status = $data['status'];
        //     if ($data['admin_comments'] == '') return false;
        //     $job->admin_comments = $data['admin_comments'];
        //     $job->save();
        //     return true;
        // }
        // return false;
    }

    private function isValidStatusChange($status)
    {
        // So this can further extended easily in future 
        return in_array($status, ['timedout']);
    }

    private function updateJobStatus($job, $data)
    {
        if (empty($data['admin_comments'])) {
            return false;
        }

        $old_status          = $job->status ?? 'null';
        $job->status         = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();

        // Try logging the status change for reference
        $this->logger->addInfo('Job status updated', [
            'job_id'        => $job->id, 
            'old_status'    => $old_status,
            'new_status'    => $job->status
        ]);
        
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if ($this->isValidAssignedStatus($data['status'])) {
            return $this->updateAssignedJobStatus($job, $data);
        }
        return false;
    }

    private function isValidAssignedStatus($status)
    {
        return in_array($status, ['withdrawbefore24', 'withdrawafter24', 'timedout']);
    }

    private function updateAssignedJobStatus($job, $data)
    {
        if ($data['status'] == 'timedout' && empty($data['admin_comments'])) {
            return false; 
        }

        $old_status          = $job->status ?? 'null';
        $job->status         = $data['status'];
        $job->admin_comments = $data['admin_comments'];

        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
            $this->sendStatusChangeNotifications($job);
        }

        $job->save();
        $this->logger->addInfo('Job status changed', [
            'job_id'        => $job->id, 
            'old_status'    => $old_status,
            'new_status'    => $job->status
        ]);

        return true;
    }

    private function sendStatusChangeNotifications($job)
    {
        $user   = $job->user()->first();
        $email  = !empty($job->user_email) ? $job->user_email : $user->email;
        $name   = $user->name;
        
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $translator = $job->translatorJobRel
        ->where('completed_at', NULL)
        ->where('cancel_at', NULL)
        ->first();

        if ($translator) {
            $translatorEmail = $translator->user->email;
            $translatorName = $translator->user->name;
            $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.job-cancel-translator', $dataEmail);
        }
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged  = false;
        $log_data           = [];

        $newTranslatorId = $this->getTranslatorId($data);
        
        if ($newTranslatorId === null) {
            return ['translatorChanged' => $translatorChanged]; 
        }

        if ($current_translator) {
            // Translator changed
            if ($current_translator->user_id != $newTranslatorId) {
                $translatorChanged = $this->updateCurrentTranslator($current_translator, $newTranslatorId, $log_data);
            }
        } else {
            // New translator assigned
            $translatorChanged = $this->assignNewTranslator($data, $job, $newTranslatorId, $log_data);
        }
        
        return $this->buildResponse($translatorChanged, $log_data);
    }

    private function getTranslatorId($data)
    {
        if (!empty($data['translator_email'])) {
            $user = User::where('email', $data['translator_email'])->first();
            return $user ? $user->id : null;
        }
        return $data['translator'] ?? null;
    }

    private function updateCurrentTranslator($current_translator, $newTranslatorId, &$log_data)
    {
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();

        $new_translator = Translator::create(array_merge($current_translator->toArray(), ['user_id' => $newTranslatorId]));
        $log_data[]     = [
            'old_translator' => $current_translator->user->email,
            'new_translator' => $new_translator->user->email
        ];

        return true;
    }

    private function assignNewTranslator($data, $job, $newTranslatorId, &$log_data)
    {
        $new_translator = Translator::create(['user_id' => $newTranslatorId, 'job_id' => $job->id]);
        $log_data[]     = [
            'old_translator' => null,
            'new_translator' => $new_translator->user->email
        ];

        return true;
    }

    private function buildResponse($translatorChanged, $log_data)
    {
        return ['translatorChanged' => $translatorChanged] + ($translatorChanged ? ['new_translator' => $log_data] : []);
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        // Remarks: Early return makes code much cleaner and readable
        if ($old_due === $new_due) {
            return ['dateChanged' => false];
        }

        return [
            'dateChanged' => true,
            'log_data'    => [
                'old_due' => $old_due,
                'new_due' => $new_due,
            ],
        ];
    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        // Remarks: Extract email code as it was common
        $user    = $job->user()->first();
        $email   = !empty($job->user_email) ? $job->user_email : $user->email;
        $name    = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag #' . $job->id;
        $data    = ['user' => $user, 'job' => $job];

        // To user
        $this->sendEmail($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            // To current translator
            $this->sendEmailToTranslator($current_translator, $subject, $data);
        }

        // To new translator
        $this->sendEmailToTranslator($new_translator, $subject, $data);
    }
    private function sendEmailToTranslator($translator, $subject, $data)
    {
        $user           = $translator->user;
        $email          = $user->email;
        $data['user']   = $user;

        $this->sendEmail($email, $user->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    private function sendEmail($email, $name, $subject, $template, $data)
    {
        try {
            $this->mailer->send($email, $name, $subject, $template, $data);
        } catch (\Exception $e) {
            // Always log errors, good for debugging
            $this->logger->error('Failed to send email', ['email' => $email, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user    = $job->user()->first();
        $email   = !empty($job->user_email) ? $job->user_email : $user->email;
        $name    = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'      => $user,
            'job'       => $job,
            'old_time'  => $old_time
        ];

        $this->sendEmail($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($translator) {
            $data['user'] = $translator;
            $this->sendEmail($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
        }
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user    = $job->user()->first();
        $email   = !empty($job->user_email) ? $job->user_email : $user->email;
        $name    = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag #' . $job->id;

        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        $this->sendEmail($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        if ($translator) {
            $data['user'] = $translator;
            $this->sendEmail($translator->email, $translator->name, $subject, 'emails.job-changed-lang', $data);
        }
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = [
            'notification_type' => 'job_expired',
        ];
        
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . ' min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers(
                $users_array, 
                $job->id, 
                $data, 
                $msg_text, 
                $this->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job            = Job::findOrFail($job_id);
        $user_meta      = $job->user->userMeta()->firstOrFail();
        $due_date_time  = Carbon::parse($job->due);

        $data = [
            'job_id'                  => $job->id,
            'from_language_id'        => $job->from_language_id,
            'immediate'               => $job->immediate,
            'duration'                => $job->duration,
            'status'                  => $job->status,
            'gender'                  => $job->gender,
            'certified'               => $job->certified,
            'due'                     => $job->due,
            'job_type'                => $job->job_type,
            'customer_phone_type'     => $job->customer_phone_type,
            'customer_physical_type'  => $job->customer_physical_type,
            'customer_town'           => $user_meta->city,
            'customer_type'           => $user_meta->customer_type,
            'job_for'                 => $this->getJobForArray($job),
            'due_date'                => $due_date_time->toDateString(),
            'due_time'                => $due_date_time->toTimeString(),
        ];

        $this->sendNotificationTranslator($job, $data, '*'); // send Push all sutiable translators
    }

    private function getJobForArray($job)
    {
        $job_for = [];

        if ($job->gender) {
            $job_for[] = $job->gender === 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified) {
            if ($job->certified === 'both') {
                $job_for[] = 'normal';
                $job_for[] = 'certified';
            } else {
                $job_for[] = $job->certified === 'yes' ? 'certified' : $job->certified;
            }
        }

        return $job_for;
    }

    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $interpretation_type = $job->customer_physical_type == 'yes' ? 'platstolkningen' : 'telefontolkningen';
        $msg_text = [
            "en" => sprintf(
                'Du har nu fått %s för %s kl %s den %s. Vänligen säkerställ att du är förberedd för den tiden. Tack!',
                $interpretation_type,
                $language,
                $duration,
                $due
            ),
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                [$user],
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $userTags = [];

        foreach ($users as $oneUser) {
            $userTags[] = [
                "key"       => "email",
                "relation"  => "=",
                "value"     => strtolower($oneUser->email),
            ];
        }

        $taggedUsers = [];
        foreach ($userTags as $index => $userTag) {
            if ($index > 0) {
                $taggedUsers[] = ["operator" => "OR"];
            }
            $taggedUsers[] = $userTag;
        }

        return json_encode($taggedUsers);
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $adminEmail       = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        $cuser = $user;
        $jobId = $data['job_id'];
        $job    = Job::findOrFail($jobId);

        // Early return
        if (Job::isTranslatorAlreadyBooked($jobId, $cuser->id, $job->due)) {
            return [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        if ($job->status === 'pending' && Job::insertTranslatorJobRel($cuser->id, $jobId)) {
            $job->status = 'assigned';
            $job->save();

            $userDetails = $job->user()->first();
            $this->sendJobAcceptanceEmail($job, $userDetails);

            $jobs = $this->getPotentialJobs($cuser);
            return [
                'status' => 'success',
                'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true)
            ];
        }

        return [
            'status'  => 'fail',
            'message' => 'Det gick inte att acceptera bokningen.'
        ];
    }


    private function sendJobAcceptanceEmail($job, $user)
    {
        $mailer  = new AppMailer();
        $email   = !empty($job->user_email) ? $job->user_email : $user->email;
        $name    = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $data = [
            'user' => $user,
            'job'  => $job
        ];

        try {
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
        } catch (\Exception $e) {
            Log::error('Email sending failed: ' . $e->getMessage());
        }
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job        = Job::findOrFail($job_id);
        $response   = [];

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            return [
                'status'  => 'fail',
                'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'
            ];
        }

        if ($job->status !== 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            return [
                'status' => 'fail',
                'message' => 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning.'
            ];
        }

        $job->status = 'assigned';
        $job->save();

        $user = $job->user()->first();
        $this->sendJobAcceptanceEmail($job, $user);

        $this->sendPushNotificationForJobAccepted($user, $job);

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        return [
            'status'    => 'success',
            'list'      => ['job' => $job],
            'message'   => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
        ];
    }

    private function sendPushNotificationForJobAccepted($user, $job)
    {
        $data = [
            'notification_type' => 'job_accepted'
        ];
        
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    public function cancelJobAjax($data, $user)
    {
        $response   = [];
        $job_id     = $data['job_id'];
        $job        = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        
        if ($user->is('customer')) {
            return $this->handleCustomerCancellation($job, $translator);
        } else {
            return $this->handleTranslatorCancellation($job, $translator);
        }
    }

    private function handleCustomerCancellation($job, $translator)
    {
        $job->withdraw_at = Carbon::now();
        $response         = [
            'status'    => 'success',
            'jobstatus' => 'success',
        ];

        if ($job->withdraw_at->diffInHours($job->due) >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }

        $job->save();
        Event::fire(new JobWasCanceled($job));

        if ($translator) {
            $this->sendTranslatorCancellationNotification($translator, $job);
        }
        
        return $response;
    }

    private function handleTranslatorCancellation($job, $translator)
    {
        $response = [];
        
        if ($job->due->diffInHours(Carbon::now()) > 24) {
            $this->notifyCustomerOfTranslatorCancellation($job);
            $this->resetJobForReassignment($job, $translator);
            $response['status']  = 'success';
        } else {
            $response['status']  = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
        }
        
        return $response;
    }

    private function sendTranslatorCancellationNotification($translator, $job)
    {
        $data     = ['notification_type' => 'job_cancelled'];
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
        ];
        
        if ($this->isNeedToSendPush($translator->id)) {
            $users_array = [$translator];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
        }
    }

    private function notifyCustomerOfTranslatorCancellation($job)
    {
        $customer = $job->user()->first();
        if ($customer) {
            $data     = ['notification_type' => 'job_cancelled'];
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $msg_text = [
                "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
            ];
            
            if ($this->isNeedToSendPush($customer->id)) {
                $users_array = [$customer];
                $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
            }
        }
    }

    private function resetJobForReassignment($job, $translator)
    {
        $job->status         = 'pending';
        $job->created_at     = now();
        $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
        $job->save();

        Job::deleteTranslatorJobRel($translator->id, $job->id);
        $data = $this->jobToData($job);
        
        // Send push notification to all suitable translators
        $this->sendNotificationTranslator($job, $data, $translator->id);
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta      = $cuser->userMeta;
        $translator_type = $cuser_meta->translator_type;
        $job_type        = $this->determineJobType($translator_type);

        $languages        = UserLanguages::where('user_id', $cuser->id)->pluck('lang_id')->all();
        $gender           = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;

        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $languages, $gender, $translator_level);
        
        return $this->filterJobs($job_ids, $cuser->id);
    }

    private function determineJobType($translator_type)
    {
        switch ($translator_type) {
            case 'professional':
                return 'paid';
            case 'rwstranslator':
                return 'rws';
            case 'volunteer':
            default:
                return 'unpaid';
        }
    }

    private function filterJobs($job_ids, $user_id)
    {
        return array_filter($job_ids, function ($job) use ($user_id) {
            $jobuserid                  = $job->user_id;
            $job->specific_job          = Job::assignedToPaticularTranslator($user_id, $job->id);
            $job->check_particular_job  = Job::checkParticularJob($user_id, $job);
            $checktown                  = Job::checkTowns($jobuserid, $user_id);

            if ($job->specific_job === 'SpecificJob' && $job->check_particular_job === 'userCanNotAcceptJob') {
                return false;
            }

            if (($job->customer_phone_type === 'no' || $job->customer_phone_type === '') && 
                $job->customer_physical_type === 'yes' && !$checktown) {
                return false;
            }

            return true;
        });
    }

    public function endJob($post_data)
    {
        $job_id     = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if ($job_detail->status !== 'started') {
            return ['status' => 'success'];
        }

        $completed_date     = now();
        $session_time       = $this->calculateSessionTime($job_detail->due, $completed_date);
        $session_explode    = explode(':', $session_time);
        $sessionFormatted   = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $job_detail->end_at       = $completed_date;
        $job_detail->status       = 'completed';
        $job_detail->session_time = $session_time;

        $user           = $job_detail->user()->first();
        $translator     = $job_detail->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorUser = $translator->user()->first();


        $this->sendSessionEndedNotification($job_detail, $session_time, 'faktura', $user);
        $this->sendSessionEndedNotification($job_detail, $session_time, 'lön', $translatorUser);
    }

    private function calculateSessionTime($dueDate, $completedDate)
    {
        $start  = Carbon::parse($dueDate);
        $end    = Carbon::parse($completedDate);
        $diff   = $end->diff($start);
        return sprintf('%d:%02d:%02d', $diff->h, $diff->i, $diff->s);
    }


    public function customerNotCall($post_data)
    {
        $job_id     = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if (!$job_detail) {
            return ['status' => 'fail', 'message' => 'Job not found.'];
        }

        $completed_date     = now();
        $job_detail->end_at = $completed_date;
        // constants can be defined in model instead i.e Job::STATUS_NOT_CARRIED_OUT_CUSTOMER;
        $job_detail->status = 'not_carried_out_customer';

        $translator = $job_detail->translatorJobRel()
        ->whereNull('completed_at')
        ->whereNull('cancel_at')
        ->first();

        if (!$translator) {
            return ['status' => 'fail', 'message' => 'Translator not found.'];
        }

        $translator->completed_at = $completed_date;
        $translator->completed_by = $translator->user_id;

        $job_detail->save();
        $translator->save();

        return ['status' => 'success'];
    }

    public function getAll(Request $request, $limit = NULL)
    {

        $requestData    = $request->all();
        $currentUser    = $request->__authenticatedUser;
        $isSuperAdmin   = $currentUser && $currentUser->user_type == env('SUPERADMIN_ROLE_ID');
        $consumerType   = $currentUser->consumer_type ?? null;

        $allJobs = Job::query()->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

        if ($isSuperAdmin) {
            $this->handleSuperAdminFilters($allJobs, $requestData);
        } else {
            $this->handleUserFilters($allJobs, $requestData, $consumerType);
        }

        $allJobs->orderBy('created_at', 'desc');

        return ($limit === 'all') ? $allJobs->get() : $allJobs->paginate(15);
    }

    private function handleSuperAdminFilters($allJobs, $requestData)
    {
        if ($this->hasFeedbackFilter($requestData)) {
            $this->applyFeedbackFilter($allJobs, $requestData);
        }

        if (!empty($requestData['id'])) {
            $this->applyIdFilter($allJobs, $requestData['id']);
        }

        $this->applyCommonFilters($allJobs, $requestData);
    }

    private function handleUserFilters($allJobs, $requestData, $consumerType)
    {
        if (!empty($requestData['id'])) {
            $allJobs->where('id', $requestData['id']);
        }

        if ($consumerType === 'RWS') {
            $allJobs->where('job_type', 'rws');
        } else {
            $allJobs->where('job_type', 'unpaid');
        }

        if ($this->hasFeedbackFilter($requestData)) {
            $this->applyFeedbackFilter($allJobs, $requestData);
        }

        $this->applyCommonFilters($allJobs, $requestData);
    }


    private function hasFeedbackFilter($requestData)
    {
        return isset($requestData['feedback']) && $requestData['feedback'] !== 'false';
    }

    private function applyFeedbackFilter($allJobs, $requestData)
    {
        $allJobs->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', 3);
                });
        
        if (isset($requestData['count']) && $requestData['count'] !== 'false') {
            return ['count' => $allJobs->count()];
        }
    }

    private function applyIdFilter($allJobs, $id)
    {
        if (is_array($id)) {
            $allJobs->whereIn('id', $id);
        } else {
            $allJobs->where('id', $id);
        }
    }

    private function applyCommonFilters($allJobs, $requestData)
    {
        if (!empty($requestData['lang'])) {
            $allJobs->whereIn('from_language_id', $requestData['lang']);
        }
        
        if (!empty($requestData['status'])) {
            $allJobs->whereIn('status', $requestData['status']);
        }
        
        if (!empty($requestData['expired_at'])) {
            $allJobs->where('expired_at', '>=', $requestData['expired_at']);
        }

        if (!empty($requestData['will_expire_at'])) {
            $allJobs->where('will_expire_at', '>=', $requestData['will_expire_at']);
        }

        if (!empty($requestData['customer_email'])) {
            $this->applyCustomerEmailFilter($allJobs, $requestData['customer_email']);
        }

        if (!empty($requestData['translator_email'])) {
            $this->applyTranslatorEmailFilter($allJobs, $requestData['translator_email']);
        }

        $this->applyDateFilters($allJobs, $requestData);
        $this->applyAdditionalFilters($allJobs, $requestData);
    }

    private function applyCustomerEmailFilter($allJobs, $emails)
    {
        $users = User::whereIn('email', $emails)->get();
        if ($users->isNotEmpty()) {
            $allJobs->whereIn('user_id', $users->pluck('id'));
        }
    }

    private function applyTranslatorEmailFilter($allJobs, $emails)
    {
        $users = User::whereIn('email', $emails)->get();
        if ($users->isNotEmpty()) {
            $jobIds = DB::table('translator_job_rel')
                ->whereNull('cancel_at')
                ->whereIn('user_id', $users->pluck('id'))
                ->pluck('job_id');
            $allJobs->whereIn('id', $jobIds);
        }
    }

    private function applyDateFilters($allJobs, $requestData)
    {
        if (!empty($requestData['filter_timetype'])) {
            $from   = $requestData['from'] ?? null;
            $to     = $requestData['to'] ?? null;

            if ($requestData['filter_timetype'] === "created") {
                if ($from) {
                    $allJobs->where('created_at', '>=', $from);
                }
                if ($to) {
                    $allJobs->where('created_at', '<=', $to . " 23:59:00");
                }
            } elseif ($requestData['filter_timetype'] === "due") {
                if ($from) {
                    $allJobs->where('due', '>=', $from);
                }
                if ($to) {
                    $allJobs->where('due', '<=', $to . " 23:59:00");
                }
            }
        }
    }

    private function applyAdditionalFilters($allJobs, $requestData)
    {
        if (!empty($requestData['job_type'])) {
            $allJobs->whereIn('job_type', $requestData['job_type']);
        }
        
        if (isset($requestData['physical'])) {
            $allJobs->where('customer_physical_type', $requestData['physical'])
                    ->where('ignore_physical', 0);
        }

        if (isset($requestData['phone'])) {
            $allJobs->where('customer_phone_type', $requestData['phone']);
            if (isset($requestData['physical'])) {
                $allJobs->where('ignore_physical_phone', 0);
            }
        }

        if (isset($requestData['flagged'])) {
            $allJobs->where('flagged', $requestData['flagged'])
                    ->where('ignore_flagged', 0);
        }

        if (isset($requestData['distance']) && $requestData['distance'] === 'empty') {
            $allJobs->whereDoesntHave('distance');
        }

        if (isset($requestData['salary']) && $requestData['salary'] === 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (isset($requestData['consumer_type']) && $requestData['consumer_type'] !== '') {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }

        if (isset($requestData['booking_type'])) {
            if ($requestData['booking_type'] === 'physical') {
                $allJobs->where('customer_physical_type', 'yes');
            } elseif ($requestData['booking_type'] === 'phone') {
                $allJobs->where('customer_phone_type', 'yes');
            }
        }
    }

    public function alerts()
    {
        $jobs       = Job::all();
        $sesJobs    = [];
        $jobId      = [];
        $diff       = [];
        $i          = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        $jobId = collect($sesJobs)->pluck('id')->all();
        // foreach ($sesJobs as $job) {
        //     $jobId [] = $job->id;
        // }

        $languages       = Language::where('active', '1')->orderBy('language')->get();
        $requestdata     =  Request::all();
        $all_customers   = User::where('user_type', '1')->lists('email');
        $all_translators = User::where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = Job::query()
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = User::where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = User::where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages          = Language::where('active', '1')->orderBy('language')->get();
        $requestdata        = Request::all();
        $all_customers      = User::where('user_type', '1')->lists('email');
        $all_translators    = User::where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = User::where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = User::where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }
        return [
            'allJobs'           => $allJobs, 
            'languages'         => $languages, 
            'all_customers'     => $all_customers, 
            'all_translators'   => $all_translators, 
            'requestdata'       => $requestdata
        ];
    }

    public function ignoreExpiring($id)
    {
        $job         = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job                 = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle         = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid  = $request['jobid'];
        $userid = $request['userid'];
        $job    = Job::find($jobid);

        if (!$job) {
            return ["Job not found!"];
        }

        $currentTimestamp = Carbon::now();
        $jobReopenData    = [
            'status'         => 'pending',
            'created_at'     => $currentTimestamp,
            'will_expire_at' => TeHelper::willExpireAt($job->due, $currentTimestamp),
            'updated_at'     => $currentTimestamp,
        ];

        $translatorData     = [
            'user_id'       => $userId,
            'job_id'        => $jobId,
            'created_at'    => $currentTimestamp,
            'updated_at'    => $currentTimestamp,
            'cancel_at'     => $currentTimestamp,
        ];

        if ($job->status != 'timedout') {
            $affectedRows = $job->update($jobReopenData);
            $newJobId     = $jobId;
        } else {
            $jobData                        = $job->toArray();
            $jobData['status']              = 'pending';
            $jobData['created_at']          = $currentTimestamp;
            $jobData['updated_at']          = $currentTimestamp;
            $jobData['will_expire_at']      = TeHelper::willExpireAt($job->due, $currentTimestamp);
            $jobData['cust_16_hour_email']  = 0;
            $jobData['cust_48_hour_email']  = 0;
            $jobData['admin_comments']      = "This booking is a reopening of booking #$jobId";

            $newJob     = Job::create($jobData);
            $newJobId   = $newJob->id;
        }
        
        Translator::where('job_id', $jobId)
        ->whereNull('cancel_at')
        ->update(['cancel_at' => $currentTimestamp]);
        Translator::create($translatorData);

         if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($newJobId);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}