<?php

namespace App\Http\Controllers;

use App\Record;
use Illuminate\Support\Facades\Http;
use App\Smsru;

class LockController extends Controller
{
    public function index()
    {
        $timeToSendSms = false;

        $smartroom = new SmartroomController();
        $records = $smartroom->getRecords()->data;
        $seances = $this->getSeances($records);

        foreach ($records as $record) {
            if (!isset($record->client->id)) {
                continue;
            }

            if ($record->prepaid && !$record->prepaid_confirmed) {
                continue;
            }

            $clientId = (int)$record->client->id;
            $seance = $seances[(int)$record->id];

            // за 60 минут до начала брони пора отправлять смс
            if ($seance->timeBeforeStart <= 3600) {
                $timeToSendSms = true;
            }

            if (!$timeToSendSms) {
                continue;
            }

            $recordsRow = Record::where('record_id', '=', $record->id)->first();

            if (!$recordsRow) {
                Record::insert(array(
                    'record_id'  => $record->id,
                    'client_id' => $record->client->id,
                    'passcode_id' => 0,
                    'passcode' => '',
                    'time_start' => $seance->timeStartText,
                    'time_end' => $seance->timeEndText,
                    'sms_status' => 0,
                    'sms_text'   => '',
                    'sms_ending_status' => 0,
                    'not_found' => 0,
                    'status' => 1
                ));
            }

            $recordsRow = Record::where('record_id', '=', $record->id)->first();

            $isMarafon = $smartroom->isMarafon($seance->services);
            $isChampionship = $smartroom->isChampionship($seance->services);
            $isNoClean = $smartroom->isNoClean($seance->comment);

            if ($isChampionship || $isNoClean) {
                $passcodeEndTime = $seance->timeEnd + 600;
            } else {
                $passcodeEndTime = $seance->timeEnd - 1200;
            }

            $passcodeStartTime = $seance->timeStart - 600;

            // Для определенных клиентов время действия кода за 20 минут до начала брони
            if (in_array($clientId, [33333333, 77777777])) {
                $passcodeStartTime = $seance->timeStart - 1200;
            }

            // если изменилось время брони
            if (($seance->timeStartText !== $recordsRow->time_start) || ($seance->timeEndText !== $recordsRow->time_end)) {

                if ($this->changePasscodeSciener($recordsRow->passcode_id, $passcodeStartTime, $passcodeEndTime)) {
                    $dateStartTextSms = '';
                    $dateEndTextSms = '';
                    $seance->timeStartTextSms = date('H:i', $seance->timeStart);
                    $timeEndTextSms = date('H:i', $seance->timeEnd);

                    if (date('Y-m-d', $seance->timeStart) !== date('Y-m-d', time())) {
                        $dateStartTextSms = date('d-m', $seance->timeStart);
                    }

                    if (date('Y-m-d', $seance->timeEnd) !== date('Y-m-d', time())) {
                        $dateStartTextSms = date('d-m', $seance->timeStart);
                    }

                    $recordsRow->time_start = $seance->timeStartText;
                    $recordsRow->time_end = $seance->timeEndText;
                    $recordsRow->save();

                    if ($seance->status !== 'finished') {
                        $smsText = "Изменилось время с $dateStartTextSms $seance->timeStartTextSms до $dateEndTextSms $timeEndTextSms\n";
                        $smsText .= "Код доступа в помещение: $recordsRow->passcode#";

                        $subject = 'Оповещение об изменении времени бронирования';

                        $client = $record->client;

                        $emailText = "<b>Клиент:</b> $client->name<br>";
                        $emailText .= "<b>Телефон:</b> $client->phone<br>";
                        $emailText .= "<b>Эл.почта:</b> $client->email<br>";
                        $emailText .= "<br>";
                        $emailText .= "<b>Текст сообщения:</b><br>";
                        $emailText .= str_replace("\n", '<br>', $smsText);

                        $this->sendSmsByClientId($record->client->id, $smsText);
                        $this->sendEmailByClientId('55555555', $emailText, $subject);
                    }
                } else {
                    echo 'Не прошло ' . $recordsRow->passcode_id;
                }
            }

            // проверим предыдущую запись
            $prevRecord = $this->checkPrevRecord($seances, $seance);

            // проверим следующую запись
            $nextRecord = $this->checkNextRecord($seances, $seance);

            if ($nextRecord->isNoBreakInTime && $nextRecord->isSameClient) {
                echo date('d-m-Y H:i', (int)$nextRecord->timeEnd);
            }

            $smsStatus = $recordsRow->sms_status;

            if ($smsStatus) {
                continue;
            }

            if ($prevRecord->isNoBreakInTime && $prevRecord->isSameClient) {
                continue;
            }

            if ($nextRecord->isNoBreakInTime && $nextRecord->isSameClient) {
                $seance->timeEnd = $nextRecord->timeEnd;
            }

            if ($passCodeData = $this->setPasscodeSciener($passcodeStartTime, $passcodeEndTime)) {
                $recordsRow->sms_status = 1;
                $recordsRow->passcode_id = $passCodeData->passCodeId;
                $recordsRow->passcode = $passCodeData->passCode;
                $recordsRow->time_start = $seance->timeStartText;
                $recordsRow->time_end = $seance->timeEndText;
                $recordsRow->save();

                $startTimeText = date('d-m-Y H:i', (int)$passCodeData->startTime);
                $endTimeText = date('d-m-Y H:i', (int)$passCodeData->endTime);

                $smsText = "Код доступа в Snooker Room на время брони: $passCodeData->passCode#";

                $subject = 'Напоминание о визите';

                $client = $record->client;

                $emailText = "<b>Клиент:</b> $client->name<br>";
                $emailText .= "<b>Телефон:</b> $client->phone<br>";
                $emailText .= "<b>Эл.почта:</b> $client->email<br>";
                $emailText .= "<br>";
                $emailText .= "<b>Текст сообщения:</b><br>";
                $emailText .= str_replace("\n", '<br>', $smsText);

                $this->sendSmsByClientId($record->client->id, $smsText);
                $this->sendEmailByClientId('55555555', $emailText, $subject);
            }
        }
    }

    public function getSeances ($records)
    {
        foreach ($records as $record) {
            $timeNow = time();
            $timeStart = strtotime($record->date);
            $timeEnd = strtotime($record->date) + $record->seance_length;
            $timeStartText = date('Y-m-d H:i:s', $timeStart);
            $timeEndText = date('Y-m-d H:i:s', $timeStart + $record->seance_length);
            $timeNowText = date('Y-m-d H:i:s', $timeNow);
            $timeBeforeStart = $timeAfterEnd = $timeAfterStart = $timeBeforeEnd = false;

            if ($timeNow < $timeStart) {
                $status = 'inWaiting';
                $timeBeforeStart = $timeStart - $timeNow;
                $message = 'До начала сеанса осталось: ' . (int)($timeBeforeStart / 60) . 'мин. ' . $timeBeforeStart % 60 . 'сек.';
            } else if ($timeNow > $timeEnd) {
                $status = 'finished';
                $timeAfterEnd = $timeNow - $timeEnd;
                $message = '';
            } else {
                $status = 'inProgress';
                $timeAfterStart = $timeNow - $timeStart;
                $timeBeforeEnd = $timeEnd - $timeNow;
                $message = '';
            }

            $seance = new \stdClass();
            $seance->timeStart = $timeStart;
            $seance->timeEnd = $timeEnd;
            $seance->status = $status;
            $seance->timeBeforeStart = $timeBeforeStart;
            $seance->timeAfterEnd = $timeAfterEnd;
            $seance->timeAfterStart = $timeAfterStart;
            $seance->timeBeforeEnd = $timeBeforeEnd;
            $seance->timeStartText = $timeStartText;
            $seance->timeEndText = $timeEndText;
            $seance->timeNowText = $timeNowText;
            $seance->message = $message;
            $seance->services = $record->services;
            $seance->comment = $record->comment;
            $seance->client = $record->client;
            $seance->recordId = $record->id;

            $seances[(int)$record->id] = $seance;
        }
        return $seances;
    }

    public function checkPrevRecord($seances, $currentSeance)
    {
        $result = new \stdClass();
        $result->isNoBreakInTime = false;
        $result->isSameClient = false;

        foreach ($seances as $seance) {
            if ((int)$seance->timeEnd == (int)$currentSeance->timeStart) {
                    $result->isNoBreakInTime = true;

                    if(isset($seance->client->id) && isset($currentSeance->client->id)) {
                        if ((int)$seance->client->id == (int)$currentSeance->client->id) {
                            $result->isSameClient = true;
                            $result->timeStart = (int)$seance->timeStart;
                        }
                    }

                    return $result;
                }
            }

        return $result;
    }

    public function checkNextRecord($seances, $currentSeance, $result = null)
    {
        if (!$result) {
            $result = new \stdClass();
            $result->isNoBreakInTime = false;
            $result->isSameClient = false;
        }

        foreach ($seances as $seance) {
            if((int)$seance->timeStart == (int)$currentSeance->timeEnd) {
                $result->isNoBreakInTime = true;

                if(isset($seance->client->id) && isset($currentSeance->client->id)) {
                    if ((int)$seance->client->id == (int)$currentSeance->client->id) {
                        $result->isSameClient = true;
                        $result->timeEnd = (int)$seance->timeEnd;
                    }
                }

                $this->checkNextRecord($seances, $seances[$seance->recordId], $result);
            }
        }

        return $result;
    }

    private function checkPasscodes ()
    {
        $listPasscode = $this->getListPasscodeSciener();

        foreach ($listPasscode->list as $passcode) {
            if ($passcode->keyboardPwdName == 'yclients') {
                $recordsRow = Record::where('passcode_id', '=', $passcode->keyboardPwdId)->first();

                if ($recordsRow) {
                    $smartroom = new SmartroomController();
                    $records = $smartroom->getRecord($recordsRow->record_id);
                    $afterEndTime = (time() - ($passcode->endDate / 1000));

                    if ($records->deleted || $afterEndTime > 86400) {
                        $this->deletePasscodeSciener($passcode->keyboardPwdId);
                    }
                } else {
                    $this->deletePasscodeSciener($passcode->keyboardPwdId);
                }
            }
        }
    }

    private function getApiTokenSciener()
    {
        $response = Http::withHeaders(['Content-Type'  => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/oauth2/token?client_id=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&client_secret=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&grant_type=password&username=%2B79999999999&password=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx&redirect_uri=n9999999.yclients.com', []);
        $accessToken = json_decode($response)->access_token;

        return $accessToken;
    }

    private function setPasscodeSciener($timeStart, $timeEnd)
    {
        $passCode = 0;
        while (!($passCode % 10)){
            $passCode = rand(1568, 9864);
        }
        $prefix = rand(0, 9);
        $passCode = (string)($passCode);
        $clientPassCode = $prefix . $prefix . $passCode;

        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&addType=2';
        $data .= '&keyboardPwdName=yclients';
        $data .= '&keyboardPwd=' . $passCode;
        $data .= '&startDate=' . $timeStart . '000';
        $data .= '&endDate=' . $timeEnd. '000';
        $data .= '&date=' . time() . '000';

        $response = Http::withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/keyboardPwd/add?' . $data, []);

        if (isset(json_decode($response)->keyboardPwdId)) {
            $keyboardPwdId = json_decode($response)->keyboardPwdId;
            $result = new \stdClass();
            $result->passCodeId = $keyboardPwdId;
            $result->passCode = $clientPassCode;
            $result->startTime = $timeStart;
            $result->endTime = $timeEnd;

            return $result;
        }

        return false;
    }

    public function getPasscodeSciener() {
        $passCode = rand(15683655, 98645487);
        $startTime = (time() - 18640);
        $endTime = (time() + 7200);
        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&keyboardPwdVersion=4';
        $data .= '&keyboardPwdType=2';
        $data .= '&keyboardPwdName=yclients';
        $data .= '&startDate=' . $startTime . '000';
        $data .= '&endDate=' . $endTime. '000';
        $data .= '&date=' . time() . '000';
        $response = Http::withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/keyboardPwd/get?' . $data, []);

        return $response;
    }

    public function getListPasscodeSciener()
    {
        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&pageNo=1';
        $data .= '&pageSize=100';
        $data .= '&date=' . time() . '000';
        $response = Http::withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/lock/listKeyboardPwd?' . $data, []);

        return json_decode($response);
    }

    public function checkIncomingByPasscode($passcodesIncoming, $passcodeOutgoing, $interval)
    {
        $lastLockDate = new \stdClass();
        $lastLockDate->incoming = 0;
        $lastLockDate->outgoing = 0;

        $listUnlock = $this->getListUnlockSciener($interval)->list;

        foreach ($listUnlock as $unlock) {
            if (in_array($unlock->keyboardPwd, $passcodesIncoming)) {
                if((int)$unlock->lockDate > $lastLockDate->incoming) {
                    $lastLockDate->incoming = (int)$unlock->lockDate;
                }
            }
            if (mb_strpos($unlock->keyboardPwd, $passcodeOutgoing) !== false ) {
                if((int)$unlock->lockDate > $lastLockDate->outgoing) {
                    $lastLockDate->outgoing = (int)$unlock->lockDate;
                }
            }
        }

        if ($lastLockDate->incoming > $lastLockDate->outgoing) {
            return true;
        }

        return false;
    }

    public function getListUnlockSciener($interval)
    {
        $startDate = time() - $interval;

        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&startDate=' . $startDate . '000';
        $data .= '&pageNo=1';
        $data .= '&pageSize=100';
        $data .= '&date=' . time() . '000';

        $response = Http::withHeaders(['Content-Type'  => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/lockRecord/list?' . $data, []);

        return json_decode($response);
    }

    public function changePasscodeSciener($passcodeId, $startTime, $endTime) {
        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&keyboardPwdId=' . $passcodeId;
        $data .= '&startDate=' . $startTime . '000';
        $data .= '&endDate=' . $endTime . '000';
        $data .= '&changeType=2';
        $data .= '&date=' . time() . '000';

        $response = Http::withHeaders(['Content-Type'  => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/keyboardPwd/change?' . $data, []);

        if (isset(json_decode($response)->errcode) && !json_decode($response)->errcode) {
            return true;
        }

        return false;
    }

    public function deletePasscodeSciener($passcodeId)
    {
        $data = 'clientId=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $data .= '&accessToken=' . $this->getApiTokenSciener();
        $data .= '&lockId=nnnnnnn';
        $data .= '&keyboardPwdId=' . $passcodeId;
        $data .= '&deleteType=2';
        $data .= '&date=' . time() . '000';
        $response = Http::withHeaders(['Content-Type'  => 'application/x-www-form-urlencoded'])
            ->post('https://api.sciener.com/v3/keyboardPwd/delete?' . $data, [
        ]);

        return json_decode($response);
    }

    public function sendSmsByClientId($clientId, $message)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer xxxxxxxxxxxxxxxxxxxx, User xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'])
            ->post('https://api.yclients.com/api/v1/sms/clients/by_id/nnnnnn',
                   [
                       "client_ids" => [$clientId],
                       "text"       => $message
                   ]);

        return json_decode($response)->success;
    }

    public function sendEmailByClientId($clientId, $message, $subject="Оповещение SnookerRoom")
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer xxxxxxxxxxxxxxxxxxxx, User xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'])
            ->post('https://api.yclients.com/api/v1/email/clients/by_id/nnnnnn',
                   [
                       "client_ids"    => [$clientId, 99999999],
                       "subject"       => $subject,
                       "text"          => $message
                   ]);

        return json_decode($response)->success;
    }

    private function sendSmsRu($phone, $message)
    {
        $smsru = new Smsru('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'); // Ваш уникальный программный ключ, который можно получить на главной странице

        $data = new \stdClass();
        $data->to = $phone;
        $data->text = $message; // Текст сообщения
        $data->from = 'SnookerRoom'; // Если у вас уже одобрен буквенный отправитель, его можно указать здесь, в противном случае будет использоваться ваш отправитель по умолчанию
        // $data->time = time() + 7*60*60; // Отложить отправку на 7 часов
        // $data->translit = 1; // Перевести все русские символы в латиницу (позволяет сэкономить на длине СМС)
        // $data->test = 1; // Позволяет выполнить запрос в тестовом режиме без реальной отправки сообщения
        // $data->partner_id = '1'; // Можно указать ваш ID партнера, если вы интегрируете код в чужую систему
        $sms = $smsru->send_one($data); // Отправка сообщения и возврат данных в переменную
        echo 'Баланс: ' . $smsru->getBalance()->balance . '<br>';
        if ($sms->status == "OK") { // Запрос выполнен успешно
            echo 'Сообщение отправлено успешно. <br>';
            echo 'Идентификатор сообщения: ' . $sms->sms_id;
        } else {
            echo "Сообщение не отправлено. ";
            echo "Код ошибки: $sms->status_code. ";
            echo "Текст ошибки: $sms->status_text.";
        }
    }

}
