<?php

//var_dump($_REQUEST);

?>
<html lang="">
<head>
    <title>Paypal payment</title>
</head>
<body>

<script>
    window.onload = function() {
        // Wenn das Fenster als Popup geöffnet wurde, schließen
        if (window.opener) {
            window.opener.postMessage({ status: "paypal-success" }, "*");
            window.close();
        }
    };
</script>

</body>
</html>