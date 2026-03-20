<?php
// config/mailer.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Intentar usar el autoloader de Composer si existe
$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
} else {
    // Si no existe vendor/autoload.php, permitimos un fallback más abajo
}

// Si Composer no cargó PHPMailer (por ejemplo cuando el proyecto incluye PHPMailer/ sin composer),
// intentamos incluir los ficheros directamente como fallback.
if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    $pharSrc = __DIR__ . '/../PHPMailer/src/';
    if (file_exists($pharSrc . 'PHPMailer.php')) {
        require_once $pharSrc . 'Exception.php';
        require_once $pharSrc . 'PHPMailer.php';
        require_once $pharSrc . 'SMTP.php';
    }
}

function sendVerificationEmail($recipientEmail, $code)
{
    $mail = new PHPMailer(true);

    try {
        // Configuraciones del Servidor SMTP desde variables de entorno
        $mail->isSMTP();
        $mail->Host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('MAIL_USERNAME') ?: 'tucorreo@gmail.com';
        $mail->Password = getenv('MAIL_PASSWORD') ?: 'tu_contraseña_de_aplicacion';

        $mailPort = getenv('MAIL_PORT');
        if ($mailPort) {
            $mail->Port = (int) $mailPort;
        } else {
            $mail->Port = 465;
        }

        $encryption = getenv('MAIL_ENCRYPTION') ?: 'ssl';
        if (strtolower($encryption) === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // Destinatarios
        $from = getenv('MAIL_FROM') ?: 'no-reply@tudominio.com';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Finanzas Personales - Seguridad';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($recipientEmail);

        // Contenido del correo de verificación
        $mail->isHTML(true);
        $mail->Subject = 'Verifica tu cuenta - Finanzas Personales';
        $appUrl = getenv('APP_URL') ?: 'http://localhost/finanzas_ves';
        $userId = getUserIdByEmail($recipientEmail);
        $verifyLink = rtrim($appUrl, '/') . '/verify.php?user_id=' . urlencode($userId) . '&code=' . urlencode($code) . '&_ts=' . time();

        // Cuerpo simple — incluimos el código y el link
        $mail->Body = "<h3>Verifica tu cuenta</h3><p>Tu código de verificación es: <strong>$code</strong></p>" .
            "<p>O haz clic en el siguiente enlace para verificar tu cuenta:</p>" .
            "<p><a href=\"$verifyLink\">Verificar mi cuenta</a></p>" .
            "<p>Si no solicitaste este correo, ignora este mensaje.</p>";

        $mail->AltBody = "Tu código de verificación es: $code. Visita $verifyLink para verificar tu cuenta.";
        $mail->CharSet = 'UTF-8';

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Registrar tanto la información de PHPMailer como el mensaje de la excepción
        $msg = "Fallo el envio de correo a $recipientEmail. PHPMailer ErrorInfo: {$mail->ErrorInfo}. Excepción: " . $e->getMessage();
        error_log($msg);
        return false;
    }
}

// Helper: intenta obtener id de usuario por email (puede devolver null si aún no existe)
function getUserIdByEmail($email)
{
    try {
        global $pdo;
        if (!isset($pdo)) {
            // intentar cargar DB config si no está
            require __DIR__ . '/db.php';
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['id'] : '';
    } catch (Exception $e) {
        return '';
    }
}
?>