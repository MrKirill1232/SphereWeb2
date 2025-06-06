<?php

use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\controller\admin\telegram;
use Ofey\Logan22\controller\config\config;
use Ofey\Logan22\model\donate\donate;
use Ofey\Logan22\model\user\user;

class betatransfer extends \Ofey\Logan22\model\donate\pay_abstract {

    //Включена/отключена платежная система
    protected static bool $enable = true;

    const BASE_URL_V1 = 'https://merchant.betatransfer.io/';
    const BASE_URL_V2 = 'https://api.betatransfer.io/';

    //Включить только для администратора
    protected static bool $forAdmin = false;

    protected static string $name = 'BetaTransfer';

    protected static array $country = ['ru', 'ua', 'crypto'];

    protected static string $currency_default = 'UAH';

    public static function inputs(): array
    {
        return [
            'public_api_key' => '',
            'secret_api_key' => '',
        ];
    }


    private function request(
        string $url,
        array $data = [],
        array $headers = [],
        string $method = 'POST',
    ): array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $CURLOPT_POST = false;

        if (strtoupper($method) == 'POST'){
            $CURLOPT_POST = true;
        }

        if ($data) {
            $CURLOPT_POST = true;

            if (strtoupper($method) == 'JSON'){
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }

        if ($CURLOPT_POST){
            curl_setopt($ch, CURLOPT_POST, true);
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $curlError = curl_error($ch);

        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($httpCode == 200) {
            $response = json_decode($response, true);
        }

        return [
            'code' => $httpCode,
            'error' => $curlError,
            'errno' => $curlErrno,
            'body' => $response,
        ];

    }

    public function payment(
        string $amount,
        string $currency,
        string $orderId,
        array $options = []
    ): array {

        $options['amount'] = $amount;
        $options['currency'] = $currency;
        $options['orderId'] = $orderId;
        $options['fullCallback'] = 0;

        $options['sign'] = $this->generateSignV1($options);

        $queryData = [
            'token' => self::getConfigValue('public_api_key'),
        ];

        return $this->request(rtrim(self::BASE_URL_V1, '/') . '/api/payment?' . http_build_query($queryData), $options);

    }


    /**
     * @return void
     * Генерируем ссылку для перехода на сайт оплаты
     */
    function create_link(): void {
        user::self()->isAuth() ?: board::notice(false, lang::get_phrase(234));
        donate::isOnlyAdmin(self::class);

        if(empty(self::getConfigValue('public_api_key')) OR empty(self::getConfigValue('secret_api_key'))){
            board::error("betatransfer token is empty");
        }
        filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?: board::notice(false, "Введите сумму цифрой");

        $donate = \Ofey\Logan22\model\server\server::getServer(user::self()->getServerId())->donate();

        $currency = config::load()->donate()->getDonateSystems(get_called_class())?->getCurrency() ?? self::getCurrency();
        $amount = self::sphereCoinSmartCalc($_POST['count'], $donate->getRatio($currency), $donate->getSphereCoinCost());
        if ($amount < 300) {
            board::notice(false, "Минимальное пополнение от 300 UAH");
        }
        if ($amount > 20000) {
            board::notice(false, "Максимальная пополнение до 20000 UAH");
        }

        $response = $this->payment(strval(round($amount, 1)), $currency, user::self()->getId() . '_' . mt_rand(0, 999999), ['paymentSystem' => 'Card']);

        if (isset($response['body'])) {
            $body = $response['body'];
            if (isset($body['status'])) {
                if ($body['status'] === 'success') {
                    // Выводим URL при успешном ответе
                    echo $body['url'];
                } elseif ($body['status'] === 'error') {
                    // Обработка ошибок
                    if (isset($body['errors']['orderId'])) {
                        // Ошибка с orderId
                        $errorMessage = $body['errors']['orderId'][0] ?? 'Неизвестная ошибка с orderId';  // Извлекаем сообщение ошибки для orderId, если оно есть
                        board::error("Ошибка: " . $errorMessage);
                    } else {
                        // Обработка других ошибок, если присутствуют
                        $errorMessage = $body['error'] ?? 'Произошла ошибка';  // Используем общий error, если он есть
                        board::error("Произошла ошибка: " . $errorMessage);
                    }
                }
            } else {
                board::error("Ошибка: статус не найден в ответе");
            }
        }
    }


    private function generateSignV1(array $options ): string {
        return md5(implode("", $options) . self::getConfigValue('secret_api_key'));
    }

    //Получение информации об оплате
    function webhook(): void {
        if (!(config::load()->donate()->getDonateSystems('betatransfer')?->isEnable() ?? false)) {
            echo 'disabled';
            exit;
        }
        file_put_contents( __DIR__ . '/debug.php', '<?php _REQUEST: ' . print_r( $_REQUEST, true ) . PHP_EOL, FILE_APPEND );

        if(empty(self::getConfigValue('public_api_key')) OR empty(self::getConfigValue('secret_api_key'))){
            board::error("betatransfer token is empty");
        }
        $sign = $_POST['sign'] ?? null;
        $amount = (float)$_POST['amount'] ?? null;
        $orderAmount = $_POST['orderAmount'] ?? 0; //Сумма без комиссии
        $orderId = $_POST['orderId'] ?? null;
        $currency = $_POST['currency'] ?? "UAH";
        if ($sign && $amount && $orderId && $this->callbackSignIsValid($sign, $amount, $orderId))
        {
            $data = explode("_", $orderId);
            $userId = (int)$data[0];
            donate::control_uuid($sign, get_called_class());
            $amount = donate::currency($amount, $currency);

            self::telegramNotice(user::getUserId($userId), $_POST['amount'], $currency, $amount, get_called_class());
            user::getUserId($userId)->donateAdd($amount)->AddHistoryDonate(amount: $amount, pay_system:  get_called_class());
            donate::addUserBonus($userId, $amount);
            die('OK');
        }
        die('FAIL');

    }

    public function callbackSignIsValid($sign, $amount, $orderId): bool
    {
        return $sign == md5($amount . $orderId . self::getConfigValue('secret_api_key'));
    }

}
