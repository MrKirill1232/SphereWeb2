<?php

use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\controller\admin\telegram;
use Ofey\Logan22\controller\config\config;
use Ofey\Logan22\model\donate\donate;
use Ofey\Logan22\model\user\user;

class morune extends \Ofey\Logan22\model\donate\pay_abstract
{

    //Включена/отключена платежная система
    protected static bool $enable = true;

    //Включить только для true
    protected static bool $forAdmin = false;

    protected static string $name = 'Morune';

    protected static array $country = ['ru'];

    protected static string $currency_default = 'RUB';

    private array $allowIP = [];

    public static function inputs(): array
    {
        return [
            'shop_id' => '',
            'secret_key' => '',
            'secret_key_additional' => '',
        ];
    }

    /**
     * @return void
     * Генерируем ссылку для перехода на сайт оплаты
     */
    function create_link(): void
    {
        user::self()->isAuth() ?: board::notice(false, lang::get_phrase(234));
        donate::isOnlyAdmin(self::class);
        filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT) ?: board::notice(false, "Введите сумму цифрой");
        $donate = \Ofey\Logan22\model\server\server::getServer(user::self()->getServerId())->donate();
        if ($_POST['count'] < $donate->getMinSummaPaySphereCoin()) {
            board::notice(false, "Минимальное пополнение: " . $donate->getMinSummaPaySphereCoin());
        }
        if ($_POST['count'] > $donate->getMaxSummaPaySphereCoin()) {
            board::notice(false, "Максимальная пополнение: " . $donate->getMaxSummaPaySphereCoin());
        }

        $shop_id = self::getConfigValue('shop_id');
        $email = user::self()->getEmail();

        $currency = config::load()->donate()->getDonateSystems(get_called_class())?->getCurrency() ?? self::getCurrency();
        $amount = self::sphereCoinSmartCalc($_POST['count'], $donate->getRatio($currency), $donate->getSphereCoinCost());

        $order_id = uniqid();
        $params = [
            'amount' => $amount,
            'order_id' => $order_id,
            'email' => $email,
            'currency' => $currency,
            'shop_id' => $shop_id,
            "custom_fields" => ["order" => user::self()->getId()],
        ];
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . self::getConfigValue('secret_key'),
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.morune.com/invoice/create');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
        $response_object = json_decode($response);
        if ($response_object->status === 200 && $response_object->status_check) {
            $url = $response_object->data->url;
            echo $url;
        } else {
            echo "Request failed: " . $response_object->error;
        }
    }

    function webhook(): void
    {
        if (!(config::load()->donate()->getDonateSystems('morune')?->isEnable() ?? false)) {
            echo 'disabled';
            exit;
        }
        $input = file_get_contents('php://input');
        $requestData = json_decode($input, true);
        file_put_contents(__DIR__ . '/debug.php', '<?php ' . print_r($input, true) . print_r($_SERVER, true) . PHP_EOL, FILE_APPEND);

        $signature = $_SERVER['HTTP_X_API_SHA256_SIGNATURE'] ?? '';
        if (!$this->checkSignature($input, $signature, self::getConfigValue('secret_key_additional'))) {
            echo 'Invalid signature';
            exit;
        }

        $status = $requestData['status'] ?? null;
        $order = $requestData['custom_fields']['order'] ?? null;
        $user_id = $order !== null ? (int)$order : null;


        if ($status == "success") {
            $invoice = $this->getInvoiceInfo(
                self::getConfigValue('secret_key'),
                $requestData['invoice_id'],
                self::getConfigValue('shop_id')
            );
            $invoice = $invoice['data'];
            $amount = $invoice['invoice_amount'];
            $currency = $invoice['currency'];
            $amount = donate::currency($amount, $currency);

            self::telegramNotice(user::getUserId($user_id), $invoice['invoice_amount'], $currency, $amount, get_called_class());
            user::getUserId($user_id)->donateAdd($amount)->AddHistoryDonate(amount: $amount, pay_system: get_called_class(), input: $input);
            donate::addUserBonus($user_id, $amount);
            echo 'YES';
        } else {
            echo 'Платеж не принят';
        }
    }

    function getInvoiceInfo(string $apiKey, string $invoiceId, string $shopId): array
    {
        $url = "https://api.morune.com/invoice/info";
        $headers = [
            "accept: application/json",
            "x-api-key: {$apiKey}",
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$url?invoice_id={$invoiceId}&shop_id={$shopId}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    private function checkSignature(string $hookJson, string $headerSignature, string $secretKey): bool
    {
        $hookArr = json_decode($hookJson, true);
        ksort($hookArr);
        $hookJsonSorted = json_encode($hookArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $calculatedSignature = hash_hmac('sha256', $hookJsonSorted, $secretKey);
        return hash_equals($headerSignature, $calculatedSignature);
    }

}
