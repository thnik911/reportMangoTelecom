<?
ini_set("display_errors", "1");
ini_set("display_startup_errors", "1");
ini_set('error_reporting', E_ALL);

$dateFrom = $_REQUEST['dateFrom'];
$dateTo = $_REQUEST['dateTo'];
$phone = $_REQUEST['phone'];
$deal = $_REQUEST['deal'];

/*

Это продолжение логики callback.
После того, как коллбэк запустился, запускается даннный скрипт через 15 минут.
Скрипт проверяет, были ли звонок с клиентом. Если звонка не было, то перезапустить коллбэк.

 */

$dateFrom = strtotime($dateFrom);
$dateTo = strtotime($dateTo);
$duble = 0;

require_once 'auth.php';
$api_key = '************************'; // Уникальный код вашей АТС. Вставьте значение за ЛК Манго.
$api_salt = '**********************'; // Ключ для создания подписи. Вставьте значение за ЛК Манго.

$isWhileForKey = 'Y';

while ($isWhileForKey == 'Y') {
    // Запрос статистики. В логике Манго, вначале необходимо отправить запрос с нужными параметрами, а затем получить готовый отчет.
    $url = 'https://app.mango-office.ru/vpbx/stats/request';
    $data = array(
        'date_from' => $dateFrom, // Интервал в датах приходит вместе с запросом из БП.
        'date_to' => $dateTo,
        'fields' => "start, to_number, disconnect_reason, answer, from_extension", // Поля, которые участвуют в выгрузке.
        'call_party' => array(
            'number' => $phone, // Искомый номер телефона, по которому формируем статистику.
        ),
    );
    $json = json_encode($data);
    $sign = hash('sha256', $api_key . $json . $api_salt);
    $postdata = array(
        'vpbx_api_key' => $api_key,
        'sign' => $sign,
        'json' => $json,
    );
    $post = http_build_query($postdata);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $response = curl_exec($ch);
    curl_close($ch);

    $key = json_decode($response);

    $key = $key->key;

    if (!empty($key)) {
        $isWhileForKey = 'N';
        break;
    } else {
        sleep(5);
        $count = $count + 1;
        if ($count == 6) {
            // Проверка на тот случай, если вдруг через 30 секунд Манго не возвращает ключ отчета. Еще ни разу такого не было, но на всякий случай заглушка.
            $notify = executeREST(
                'im.notify.system.add',
                array(
                    'USER_ID' => 1,
                    'MESSAGE' => 'По телефону ' . $phone . ' возникла ошибка получения данных по звоноку в течении 30 секунд. Разрыв в цикле key',
                ),
                $domain, $auth, $user);
            exit;
        }
    }

}

// Выгрузка результата
$url = 'https://app.mango-office.ru/vpbx/stats/result';
$data = array(
    'key' => $key,
);
$json = json_encode($data);
$sign = hash('sha256', $api_key . $json . $api_salt);
$postdata = array(
    'vpbx_api_key' => $api_key,
    'sign' => $sign,
    'json' => $json,
);
$post = http_build_query($postdata);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
$response = curl_exec($ch);
curl_close($ch);

$response = explode(';', $response);

if (empty($response)) {
    $newCall = 'Y';
} else {
    $caller = trim(array_pop($response));
    $timeStartOfCall = array_shift($response);
    $statusOfCall = $response[2];

    if ($statusOfCall > 0) {
        $newCall = 'N';
    } else {
        $newCall = 'Y';
    }

}

if ($newCall == 'Y') {
    // Если вдруг звонка не было, то запускается БП, который повторно пробует дозвониться до менеджеров.

    $startworkflow = executeREST(
        'bizproc.workflow.start',
        array(
            'TEMPLATE_ID' => '1623',
            'DOCUMENT_ID' => array(
                'crm', 'CCrmDocumentDeal', 'DEAL_' . $deal,
            ),
            'PARAMETERS' => array(
                'Parameter1' => 'Второй и тд запуск',
                'Parameter2' => 'statusOfCall (if == 0 - call is not successful): ' . $statusOfCall . ' timeStartOfCall: ' . date('d.m.Y H:i:s', $timeStartOfCall),
            ),
        ),
        $domain, $auth, $user);
} else {
    // Если звонок все-таки был, то производим некоторые манипуляции со сделкой. В примере ниже, меняем стадию и записываем в поле дату коммуникации.

    $updatedeal = executeREST(
        'crm.deal.update',
        array(
            'ID' => $deal,
            'FIELDS' => array(
                'STAGE_ID' => 10,
                'UF_CRM_1677488494' => date('d.m.Y H:i:s', $timeStartOfCall),
            ),
            'PARAMS' => array(
                'REGISTER_SONET_EVENT' => "N",
            ),
        ),
        $domain, $auth, $user);

}

function executeREST($method, array $params, $domain, $auth, $user)
{
    $queryUrl = 'https://' . $domain . '/rest/' . $user . '/' . $auth . '/' . $method . '.json';
    $queryData = http_build_query($params);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_POST => 1,
        CURLOPT_HEADER => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_URL => $queryUrl,
        CURLOPT_POSTFIELDS => $queryData,
    ));
    return json_decode(curl_exec($curl), true);
    curl_close($curl);
}

function writeToLog($data, $title = '')
{
    $log = "\n------------------------\n";
    $log .= date("Y.m.d G:i:s") . "\n";
    $log .= (strlen($title) > 0 ? $title : 'DEBUG') . "\n";
    $log .= print_r($data, 1);
    $log .= "\n------------------------\n";
    file_put_contents(getcwd() . '/logs/reportMango.log', $log, FILE_APPEND);
    return true;
}
