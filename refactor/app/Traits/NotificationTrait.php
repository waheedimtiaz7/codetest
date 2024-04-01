<?php

namespace DTApi\Traits;

use Carbon\Carbon;
use App\Helpers\TeHelper;

trait NotificationTrait
{

    /**
     * Send a job ended notification to the user or translator.
     *
     * @param string $email The recipient's email address.
     * @param string $name The recipient's name.
     * @param Job $job The job instance.
     * @param string $sessionTime Formatted session time.
     * @param string $forText Additional text for the email.
     */
    protected function sendEmail($email, $name, $job, $sessionTime, $forText)
    {
        $data = [
            'user' => $job->user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => $forText,
        ];
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);
    }

    /**
     * Get the job message template.
     *
     * @param Job $job
     * @param UserMeta $jobPosterMeta
     * @return string
     */
    protected function getJobMessageTemplate(Job $job, UserMeta $jobPosterMeta): string
    {
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ?? $jobPosterMeta->city;

        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            return trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);
        }

        return trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
    }

    protected function initializeLogger()
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        return $logger;
    }

    protected function getOneSignalConfig()
    {
        if (env('APP_ENV') == 'prod') {
            return [
                'appId' => config('app.prodOnesignalAppID'),
                'restAuthKey' => "Authorization: Basic " . config('app.prodOnesignalApiKey'),
            ];
        } else {
            return [
                'appId' => config('app.devOnesignalAppID'),
                'restAuthKey' => "Authorization: Basic " . config('app.devOnesignalApiKey'),
            ];
        }
    }

    protected function selectSoundBasedOnJob($data)
    {
        if ($data['notification_type'] === 'suitable_job') {
            return $data['immediate'] === 'no' ? ['normal_booking', 'normal_booking.mp3'] : ['emergency_booking', 'emergency_booking.mp3'];
        }
        return ['default', 'default'];
    }

    protected function prepareNotificationFields($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        [$android_sound, $ios_sound] = $this->selectSoundBasedOnJob($data);
        $onesignalConfig = $this->getOneSignalConfig();

        $fields = [
            'app_id' => $onesignalConfig['appId'],
            'tags' => $this->getUserTagsStringFromArray($users), // Assuming this returns a JSON string
            'data' => array_merge($data, ['job_id' => $job_id]),
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msg_text,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $android_sound,
            'ios_sound' => $ios_sound,
        ];

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString(); // Assuming this returns the correct string format
        }

        return json_encode($fields);
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    protected function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = $this->initializeLogger();
        $onesignalConfig = $this->getOneSignalConfig();
        $fields = $this->prepareNotificationFields($users, $job_id, $data, $msg_text, $is_need_delay);

        $ch = curl_init("https://onesignal.com/api/v1/notifications");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', $onesignalConfig['restAuthKey']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $logger->addError('CURL error: ' . curl_error($ch), [$job_id]);
        } else {
            $logger->addInfo('Push sent for job ' . $job_id, [$response]);
        }

        curl_close($ch);
    }


    protected function prepareAndSendEmail($job, $recipient, $template, $subjectKey, $extraData = []) {
        $email = !empty($recipient->user_email) ? $recipient->user_email : $recipient->email;
        $name = $recipient->name;

        $data = array_merge([
            'user' => $recipient,
            'job' => $job,
        ], $extraData);

        $subject = $this->getSubjectForEmail($subjectKey, $job);
        $this->mailer->send($email, $name, $subject, $template, $data);
    }

    protected function getSubjectForEmail($key, $job) {
        $subjects = [
            'change_status' => "Meddelande om tilldelning av tolkuppdrag för uppdrag # {$job->id})",
            'session_ended' => "Information om avslutad tolkning för bokningsnummer #{$job->id}",
            'job_accepted' => "Bekräftelse - tolk har accepterat er bokning (bokning # {$job->id})",
        ];

        return $subjects[$key] ?? "Notification for Job #{$job->id}";
    }

    protected function sendEmailNotification(Job $job, $userId)
    {
        $recipient = $job->user;
        $subject = "Information om avslutad tolkning för bokningsnummer # {$job->id}";
        $sessionTime = explode(':', $job->session_time);
        $formattedSessionTime = "{$sessionTime[0]} tim {$sessionTime[1]} min";
        $data = [
            'user' => $recipient,
            'job' => $job,
            'session_time' => $formattedSessionTime,
            'for_text' => 'faktura'
        ];

        $mailer = new AppMailer();
        $mailer->send($recipient->email, $recipient->name, $subject, 'emails.session-ended', $data);
    }

}