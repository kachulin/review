<?php

namespace App\Http\Controllers;

use App\Record;
use App\Security;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SmartroomController extends Controller
{
    public function index(Request $request)
    {
        $table = Record::all();

        $switch = new \stdClass();
        $switch->tableHeat = 'off';
        $switch->mainLight = 'off';
        $switch->tableLight = 'off';
        $switch->tv = 'off';

        $isTimeBeforeSeanse = false;
        $isTimeAfterSeanse = false;

        $isTimeSecuritySwitchOff = false;
        $isTimeSecuritySwitchOn = false;

        $seances = [];
        $indexSeance = 0;
        $activeSeance = [];

        $records = $this->getRecords();

        foreach ($records->data as $record) {
            if (!isset($record->client->id)) {
                continue;
            }

            if ($record->prepaid && !$record->prepaid_confirmed) {
                continue;
            }

            $clientId = (int)$record->client->id;
            $isClubMember = $this->isClubMember($clientId);

            $timeNow = time();
            $timeStart = strtotime($record->date);
            $timeEnd = strtotime($record->date) + $record->seance_length;
            $timeStartText = date('Y-m-d H:i:s', $timeStart);
            $timeEndText = date('Y-m-d H:i:s', $timeStart + $record->seance_length);
            $timeNowText = date('Y-m-d H:i:s', $timeNow);
            $timeBeforeStart = $timeStart - $timeNow;
            $timeAfterStart = $timeNow - $timeStart;
            $timeBeforeEnd = $timeEnd - $timeNow;
            $timeAfterEnd = $timeNow - $timeEnd;

            if ($timeNow < $timeStart) {
                $status = 'inWaiting';
                $message = 'До начала сеанса осталось: ' . (int)($timeBeforeStart / 60) . 'мин. ' . $timeBeforeStart % 60 . 'сек.';
            } else if ($timeNow > $timeEnd) {
                $status = 'finished';
                $message = '';
            } else {
                $status = 'inProgress';
                $message = '';
            }

            $seances[$indexSeance] = [
                'start'             => $timeStart,
                'end'               => $timeEnd,
                'status'            => $status,
                'timeBeforeStart'   => $timeBeforeStart,
                'timeAfterEnd'      => $timeAfterEnd,
                'timeAfterStart'    => $timeAfterStart,
                'timeBeforeEnd'     => $timeBeforeEnd,
                'timeStartText'     => $timeStartText,
                'timeEndText'       => $timeEndText,
                'timeNowText'       => $timeNowText,
                'message'           => $message,
                'comment'           => $record->comment,
                'services'          => $record->services,
                'client'            => $record->client,
                'recordId'          => $record->id
            ];

            if ($status === 'inWaiting') {
                // Подогрев стола за 60 минут до начала брони
                if ($timeBeforeStart <= 3600) {
                    $switch->tableHeat = true;
                }

                // Время до сеанса 10 минут для дежурного освещения
                if ($timeBeforeStart <= 600) {
                    $isTimeBeforeSeanse = true;
                }

                // Время до сеанса 20 минут для дежурного освещения для определенных клиентов
                if ($timeBeforeStart <= 1200 && in_array($clientId, [55555555,77777777])) {
                    $isTimeBeforeSeanse = true;
                }

                // Отключение сигнализации за 20 минут до сеанса в течении 5 минут
                if ($timeBeforeStart <= 1200 && $timeBeforeStart > 900 && $isClubMember) {
                    $isTimeSecuritySwitchOff = true;
                }

                // Для определенных клиентов
                if (in_array($clientId, [55555555,77777777])) {
                    $isTimeSecuritySwitchOff = false;

                    // Отключение сигнализации за 30 минут до сеанса в течении 5 минут
                    if ($timeBeforeStart <= 1800 && $timeBeforeStart > 1500) {
                        $isTimeSecuritySwitchOff = true;
                    }
                }
            }

            if ($status === 'inProgress') {
                $activeSeance = $seances[$indexSeance];
            }

            if ($status === 'finished') {
                if (($this->isChampionship($record->services) || $this->isNoClean($record->comment)) && $timeAfterEnd <= 900) {
                    $isTimeAfterSeanse = true;
                }

                // Для чемпионатов и с коментарием *Без уборки* постановка на охрану 25 минут после брони в течении 5 минут.
                if (($this->isChampionship($record->services) || $this->isNoClean($record->comment)) && $timeAfterEnd >= 1500 && $timeAfterEnd < 1800) {
                    $isTimeSecuritySwitchOn = true;
                }
            }
            $indexSeance++;
        }

        $lock = new LockController();
        $isEnteredAdminPasscode = $lock->checkIncomingByPasscode(['1111', '2222', '3333'], '00000', 1800);

        if (count($activeSeance)) {
            $isTimeSecuritySwitchOn = false;

            $isClubMember = $this->isClubMember((int)$activeSeance['client']->id);

            // если услуга - Марафон, то приборы работают все время брони
            $isMarafon = $this->isMarafon($activeSeance['services']);

            // если услуга - Чемпионат, то приборы работают все время брони
            $isChampionship = $this->isChampionship($activeSeance['services']);

            // без уборки
            $isNoClean = $this->isNoClean($activeSeance['comment']);

            // проверим предыдущую запись
            $prevRecord = $this->checkPrevRecord($seances, $activeSeance);

            // проверим следующую запись
            $nextRecord = $this->checkNextRecord($seances, $activeSeance);

            if ($isChampionship || $isNoClean) {
                $timeBeforeEndForSendSms = 900;
            } else {
                $timeBeforeEndForSendSms = 2700;
            }

            $recordsRow = Record::where('record_id', '=', $activeSeance['recordId'])->first();

            if ($activeSeance['timeBeforeEnd'] <= $timeBeforeEndForSendSms && !$recordsRow->sms_ending_status && !($nextRecord->isNoBreakInTime && $nextRecord->isSameClient)) {
                $recordsRow->sms_ending_status = 1;
                $recordsRow->save();
                $smsText = "Осталось 15 минут\n";

                if ($nextRecord->isNoBreakInTime) {
                    $smsText .= "Продлить нельзя";
                } else {
                    $smsText .= "Продлить https://n9999999.yclients.com";
                }

                $subject = 'Оповещение об окончании игрового времени';

                $client = $activeSeance['client'];

                $emailText = "<b>Клиент:</b> $client->name<br>";
                $emailText .= "<b>Телефон:</b> $client->phone<br>";
                $emailText .= "<b>Эл.почта:</b> $client->email<br>";
                $emailText .= "<br>";
                $emailText .= "<b>Текст сообщения:</b><br>";
                $emailText .= str_replace("\n", '<br>', $smsText);

                $lock->sendSmsByClientId($client->id, $smsText);
                $lock->sendEmailByClientId('55555555', $emailText, $subject);
            }

            // Подогрев стола с начала и за 20 минут до окончания брони
            if (($activeSeance['timeAfterStart'] >= 0 && $activeSeance['timeBeforeEnd'] >= 1200) || $isChampionship || $isNoClean || ($nextRecord->isNoBreakInTime && $nextRecord->isSameClient)) {
                $switch->tableHeat = true;
            }

            // Освещение и телевизор с начала и за 20 минут до окончания брони
            if (($activeSeance['timeAfterStart'] >= 0 && $activeSeance['timeBeforeEnd'] >= 1200) || $isChampionship || $isNoClean || ($nextRecord->isNoBreakInTime && $nextRecord->isSameClient)) {
                $switch->mainLight = 'on';
                $switch->tv = 'on';
            } else {
                if ($isEnteredAdminPasscode) {
                    $isTimeSecuritySwitchOn = false;
                    $isTimeSecuritySwitchOff = false;
                    $switch->mainLight = 'on';
                    $switch->tv = 'on';
                } else {
                    $switch->mainLight = 'off';
                    $switch->tv = 'off';
                }
            }

            // Освещение стола с начала и за 30 минут до окончания брони
            if (($activeSeance['timeAfterStart'] >= 0 && $activeSeance['timeBeforeEnd'] >= 1800) || $isChampionship || $isNoClean || ($activeSeance['timeAfterStart'] >= 0 && $nextRecord->isNoBreakInTime && $nextRecord->isSameClient) || ($activeSeance['timeAfterStart'] < 0 && $prevRecord->isNoBreakInTime && $prevRecord->isSameClient)) {
                $switch->tableLight = 'on';
            } else {
                if ($isEnteredAdminPasscode) {
                    $isTimeSecuritySwitchOn = false;
                    $isTimeSecuritySwitchOff = false;
                    $switch->tableLight = 'on';
                } else {
                    $switch->tableLight = 'off';
                }
            }

            // Включение сигнализации за 15 минут до окончания брони в течении 5 минут
            if (($activeSeance['timeBeforeEnd'] >= 600 && $activeSeance['timeBeforeEnd'] < 900) && !($isChampionship || $isNoClean || ($nextRecord->isNoBreakInTime && $nextRecord->isClubMember))) {
                $isTimeSecuritySwitchOn = true;
            }
        } else {
            // Проверяем не вводился ли дежурный код за последние 30 минут
            if ($isEnteredAdminPasscode) {
                $isTimeSecuritySwitchOn = false;
                $isTimeSecuritySwitchOff = false;
                $switch->mainLight = 'on';
                $switch->tv = 'on';
                $switch->tableLight = 'on';
            } else {
                $switch->mainLight = 'off';
                $switch->tv = 'off';
                $switch->tableLight = 'off';
            }
        }

        if ($isTimeBeforeSeanse || $isTimeAfterSeanse) {
            $switch->mainLight = 'on';
            $switch->tv = 'on';
        }

        $securityRow = Security::where('id', '=', 1)->first();

        if ($isTimeSecuritySwitchOff) {
            $securityOff = 1;
        }
        else {
            $securityOff = 0;
        }

        if ($isTimeSecuritySwitchOn) {
            $securityOn = 1;
        }
        else {
            $securityOn = 0;
        }

        if ($isTimeSecuritySwitchOff) {
            $securityStatus = 0;
        }
        else if ($isTimeSecuritySwitchOn) {
            $securityStatus = 1;
        }
        else {
            $securityStatus = -1;
        }

        Security::insert(array(
            'status'  => $securityStatus,
            'on' => $securityOn,
            'off' => $securityOff,
            'updated_at' => date('Y-m-d H:i:s', time())
        ));

        $this->sendCommandTuya('подогрев', $switch->tableHeat);
        $this->sendCommandTuya('освещение', $switch->mainLight);
        $this->sendCommandTuya('телевизор', $switch->tv);
        $this->sendCommandTuya('стол', $switch->tableLight);

        return view('smartroom', [
            'table'             => $table,
            'today'             => $this->today(),
            'records'           => $records,
            'deviceStatuses'    => $this->getDeviceStatusesTuya(),
            'seanceTimes'       => $seances
        ]);
    }

    public function isMarafon($services)
    {
        foreach ($services as $service) {
            if ($service->title === 'Марафон') {
                return true;
            }
        }

        return false;
    }

    public function isClubMember($clientId)
    {
        $client = $this->getClient($clientId);

        foreach($client->categories as $category) {
            if ((string)$category->title === 'Член Клуба') {
                return true;
            }
        }

        return false;
    }

    public function isChampionship($services)
    {
        foreach($services as $service) {
            if (mb_strpos($service->title, 'Чемпионат Свердловской Области') !== false) {
                return true;
            }
            if (mb_strpos($service->title, 'Кубок Урала турнир') !== false) {
                return true;
            }
            if (mb_strpos($service->title, 'Чемпионат 6 красных 1 встреча (время по факту)') !== false) {
                return true;
            }
            if (mb_strpos($service->title, 'Чемпионат') !== false) {
                return true;
            }
            if (mb_strpos($service->title, 'Кубок') !== false) {
                return true;
            }
        }

        return false;
    }

    public function isNoClean($comment)
    {
        if (mb_strpos(mb_strtolower($comment), 'без уборки') !== false) {
            return true;
        }

        return false;
    }

    public function checkPrevRecord($seances, $activeSeance)
    {
        $result = new \stdClass();
        $result->isNoBreakInTime = false;
        $result->isSameClient = false;
        foreach ($seances as $seance) {
            if($seance['status'] == 'finished') {
                if((int)$seance['end'] == (int)$activeSeance['start']) {
                    $result->isNoBreakInTime = true;
                    if((int)$seance['client']->id == (int)$activeSeance['client']->id) {
                        $result->isSameClient = true;
                    }

                    return $result;
                }
            }
        }

        return $result;
    }

    public function checkNextRecord($seances, $activeSeance)
    {
        $result = new \stdClass();
        $result->isNoBreakInTime = false;
        $result->isSameClient = false;

        foreach ($seances as $seance) {
            if($seance['status'] == 'inWaiting') {
                if((int)$seance['start'] == (int)$activeSeance['end']) {
                    $result->isNoBreakInTime = true;
                    $result->isClubMember = $this->isClubMember((int)$seance['client']->id);

                    if((int)$seance['client']->id == (int)$activeSeance['client']->id) {
                        $result->isSameClient = true;
                    }

                    return $result;
                }
            }
        }

        return $result;
    }

    public function today()
    {
        return date('Y-m-d H:i:s', time());
    }

    public function getRecords()
    {
        $oneDay = 3600*24;// 24 часа
        $prevDay = date('Y-m-d', time() - $oneDay);
        $nextDay = date('Y-m-d', time() + $oneDay);

        $response = Http::withHeaders(
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer xxxxxxxxxxxxxxxxxxxx, User xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
            ])
            ->get('https://api.yclients.com/api/v1/records/999999/',
                  [
                      'start_date' => $prevDay,
                      'end_date' => $nextDay
                  ]);

        return json_decode($response);
    }

    public function getRecord($recordId)
    {
        $oneDay = 3600*24;
        $prevDay = date('Y-m-d', time() - $oneDay);
        $nextDay = date('Y-m-d', time() + $oneDay);

        $response = Http::withHeaders(
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer xxxxxxxxxxxxxxxxxxxx, User xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
            ])
            ->get('https://api.yclients.com/api/v1/record/999999/' . $recordId, []);

        return json_decode($response);
    }

    public function getClient($clientId)
    {
        $oneDay = 3600*24;//24 часа
        $prevDay = date('Y-m-d', time() - $oneDay);
        $nextDay = date('Y-m-d', time() + $oneDay);

        $response = Http::withHeaders(
            [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer xxxxxxxxxxxxxxxxxxxx, User xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
            ])
            ->get('https://api.yclients.com/api/v1/client/999999/' . $clientId, []);

        return json_decode($response);
    }

    private function sendCommandTuya($deviceName, $switch = false)
    {
        $devices = $this->getDevices();
        $device = $devices[mb_strtolower($deviceName)];

        $switch === 'on' || $switch === 1 || $switch === true ? $value = true : $value = false;
        $accessData = $this->getTuyaAccessData();

        $response = Http::withHeaders(
            [
                'Content-Type'  => 'application/json',
                't'             => $accessData['timestamp'],
                'access_token'  => $accessData['accessToken'],
                'client_id'     => $accessData['clientId'],
                'sign'          => $accessData['signature'],
                'sign_method'   => 'HMAC-SHA256'
            ])
            ->post('https://openapi.tuyaeu.com/v1.0/devices/' . $device . '/commands',
                   [
                       "commands" =>
                           [
                               [
                                   "code" => "switch_1",
                                   "value" => $value
                               ]
                           ]
                   ]);

        $result = json_decode($response);

        return $result;
    }

    private function getDeviceStatusesTuya()
    {
        $devices = $this->getDevices();

        foreach ($devices as $key => $device) {
            $deviceStatuses[$key] = $this->getDeviceStatusTuya($device);
        }

        return $deviceStatuses;
    }

    private function getDeviceStatusTuya($device)
    {
        $accessData = $this->getTuyaAccessData();
        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            't'             => $accessData['timestamp'],
            'access_token'  => $accessData['accessToken'],
            'client_id'     => $accessData['clientId'],
            'sign'          => $accessData['signature'],
            'sign_method'   => 'HMAC-SHA256'
        ])->get('https://openapi.tuyaeu.com/v1.0/devices/' . $device . '/status');

        $status = json_decode($response)->result;

        return $status;
    }

    private function getDevices()
    {
        $devices = [
            'стол'                      => 'aaaaaaaaaaaaaaaaaaaa',
            'освещение'                 => 'bbbbbbbbbbbbbbbbbbbb',
            'телевизор'                 => 'cccccccccccccccccccc',
            'подогрев'                  => 'dddddddddddddddddddd'
        ];

        return $devices;
    }

    private function getTuyaAccessData($forGetToken = false)
    {
        $clientId = 'xxxxxxxxxxxxxxxxxxx';
        $secret = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $timestamp = time() . '000';
        $accessToken = '';

        if (!$forGetToken) {
            $accessToken = $this -> getTokenTuya($clientId, $secret, $timestamp);
        }

        $data = [
            'clientId'      => $clientId,
            'secret'        => $secret,
            'timestamp'     => $timestamp,
            'accessToken'   => $accessToken,
            'signature'     => $this->getSignTuya($clientId, $secret, $timestamp, $accessToken)
        ];

        return $data;
    }

    private function getTokenTuya($clientId, $secret, $timestamp)
    {
        $signature = $this->getSignTuya($clientId, $secret, $timestamp);

        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            't'             => $timestamp,
            'client_id'     => $clientId,
            'sign'          => $signature,
            'sign_method'   => 'HMAC-SHA256'
        ])->get('https://openapi.tuyaeu.com/v1.0/token', [
            'grant_type' => '1'
        ]);
        $accessToken = json_decode($response)->result->access_token;

        return $accessToken;
    }

    private function getSignTuya($clientId, $secret, $timestamp, $accessToken = '')
    {
        return strtoupper(hash_hmac('sha256', $clientId . $accessToken . $timestamp, $secret));
    }

    private function switchAllDevices($switch)
    {
        if ($switch != 'on' && $switch != 'off') {
            $switch = 'off';
        }

        $this->sendCommandTuya('освещение', $switch);
        $this->sendCommandTuya('телевизор', $switch);
        $this->sendCommandTuya('стол', $switch);
        $this->sendCommandTuya('подогрев', $switch);
    }

}
