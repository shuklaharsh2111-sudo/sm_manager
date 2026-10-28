
<?php
// Generate a secure password hash.

$password = "your password";

$hashedPassword = password_hash(
    $password,
    PASSWORD_DEFAULT
);

echo "<h3>Password Hash Generated</h3>";

echo "<p><strong>Original Password:</strong> "
    . htmlspecialchars($password, ENT_QUOTES, 'UTF-8')
    . "</p>";

echo "<p><strong>Hashed Password:</strong></p>";

echo "<textarea rows='4' cols='70' readonly>"
    . htmlspecialchars($hashedPassword, ENT_QUOTES, 'UTF-8')
    . "</textarea>";
?>