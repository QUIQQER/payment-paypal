<?php

$status = $_GET['status'] ?? 'error';
$orderHash = $_GET['orderHash'] ?? '';
$redirect = $_GET['redirect'] ?? '/';

if ($status !== 'success') {
    $status = 'error';
}
?>
<html lang="">
<head>
    <title>Paypal payment</title>
</head>
<body>

<script>
    window.onload = function () {
        const payload = {
            source: "quiqqer-payment-paypal-recurring",
            status: <?php echo json_encode($status); ?>,
            orderHash: <?php echo json_encode($orderHash); ?>,
            redirect: <?php echo json_encode($redirect); ?>
        };

        if (window.opener) {
            window.opener.postMessage(payload, window.location.origin);
            window.close();
            return;
        }

        window.location.href = payload.redirect;
    };
</script>

</body>
</html>
