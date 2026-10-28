<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/config/db.php';
$error = '';
$emailContact = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailContact = trim($_POST['email_contact'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($emailContact === '' || $password === '') {
        $error = 'Please enter your email/contact and password.';
    } else {
        $sql = "SELECT id, fullname, email, contact, password, role
                FROM users
                WHERE email = ? OR contact = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $emailContact, $emailContact);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            if ($user && password_verify($password, $user['password'])) {
                $roles = json_decode($user['role'] ?? '', true);
                if (!is_array($roles) || empty($roles)) {
                    $error = 'Your account does not have a valid role.';
                } else {
                    $allowedRoles = ['des', 'sme', 'smi', 'admin'];
                    $role = null;
                    foreach ($roles as $assignedRole) {
                        if (!is_string($assignedRole)) {
                            continue;
                        }
                        $assignedRole = strtolower(trim($assignedRole));
                        if (in_array($assignedRole, $allowedRoles, true)) {
                            $role = $assignedRole;
                            break;
                        }
                    }
                    if ($role === null) {
                        $error = 'You do not have permission to access this dashboard.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user'] = (int) $user['id'];
                        $_SESSION['user_id'] = (int) $user['id'];
                        $_SESSION['fullname'] = $user['fullname'] ?? '';
                        $_SESSION['email'] = $user['email'] ?? '';
                        $_SESSION['contact'] = $user['contact'] ?? '';
                        $_SESSION['role'] = $role;
                        $dashboardRoutes = [
                            'des'   => 'des/des.php',
                            'sme'   => 'sme/sme.php',
                            'smi'   => 'smi/smi.php',
                            'admin' => 'admin/admin.php'
                        ];
                        header('Location: ' . $dashboardRoutes[$role]);
                        exit;
                    }
                }
            } else {
                $error = 'Invalid email/contact or password.';
            }
        } else {
            error_log('Login query preparation failed: ' . $conn->error);
            $error = 'Unable to process login. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#080b1a">
    <meta name="description" content="Secure access to the Unstoppable Solutions Creative Manager workspace.">
    <title>Creative Manager | Unstoppable Solutions</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="ambient ambient-one"></div>
    <div class="ambient ambient-two"></div>
    <div class="ambient ambient-three"></div>
    <div class="background-grid"></div>
    <main class="login-layout">
        <!-- SECTION 1: BRANDING AND OVERVIEW -->
        <section class="brand-section">
            <a href="index.php" class="brand-logo" aria-label="Creative Manager home">
                <span class="logo-symbol">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <rect x="3" y="3" width="42" height="42" rx="14"
                            fill="url(#logoGradient)" />
                        <path d="M31 17.5C29.1 15.8 26.9 15 24.2 15
                                 C18.7 15 15 18.7 15 24
                                 C15 29.3 18.7 33 24.2 33
                                 C27 33 29.3 32.1 31 30.3"
                            stroke="white" stroke-width="3.2"
                            stroke-linecap="round" />
                        <path d="M25 24H34M30 20L34 24L30 28"
                            stroke="white" stroke-width="2.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <defs>
                            <linearGradient id="logoGradient" x1="3" y1="3"
                                x2="45" y2="45">
                                <stop stop-color="#A855F7" />
                                <stop offset="1" stop-color="#4F46E5" />
                            </linearGradient>
                        </defs>
                    </svg>
                </span>
                <span class="brand-name">
                    <strong>UNSTOPPABLE</strong>
                    <small>SOLUTIONS</small>
                </span>
            </a>
            <div class="brand-content">
                <div class="eyebrow">
                    <span class="eyebrow-dot"></span>
                    YOUR CREATIVE WORKSPACE
                </div>
                <h1>
                    Bring your
                    <span class="gradient-text">creative vision</span>
                    to life.
                </h1>
                <p class="brand-description">
                    One workspace. Better collaboration. Smarter creative
                    management. Everything your team needs to keep work
                    organized and moving forward.
                </p>
                <div class="feature-list">
                    <div class="feature-item">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="16" rx="3"
                                    stroke="currentColor" stroke-width="1.6" />
                                <path d="M3 9H21M8 4V9M16 4V9M8 14H11M8 17H15"
                                    stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                        </span>
                        <span>
                            <strong>Organized workflows</strong>
                            <small>Keep creative activities structured.</small>
                        </span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="9" cy="8" r="3"
                                    stroke="currentColor" stroke-width="1.6" />
                                <path d="M3.5 20V18C3.5 15.8 5.3 14 7.5 14H10.5
                                         C12.7 14 14.5 15.8 14.5 18V20
                                         M16 5.5C17.7 5.8 19 7.2 19 9
                                         C19 10.8 17.7 12.2 16 12.5
                                         M17 15H17.5C19.7 15 21 16.5 21 18.5V20"
                                    stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                        </span>
                        <span>
                            <strong>Team collaboration</strong>
                            <small>Keep people and projects connected.</small>
                        </span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M4 19V12M10 19V5M16 19V9M22 19V3"
                                    stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" />
                            </svg>
                        </span>
                        <span>
                            <strong>Centralized management</strong>
                            <small>Access your workspace in one place.</small>
                        </span>
                    </div>
                </div>
                <div class="brand-divider"></div>
                <div class="brand-bottom">
                    <span class="orbit-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                    <p>
                        Designed for focus.
                        <strong>Built for progress.</strong>
                    </p>
                </div>
            </div>
            <div class="brand-footer">
                <span>CREATIVE MANAGER</span>
                <span class="footer-separator"></span>
                <span>WORKSPACE ACCESS</span>
            </div>
        </section>
        <!-- SECTION 2: LOGIN FORM -->
        <section class="login-section">
            <div class="login-card">
                <div class="login-topline">
                    <span class="access-label">
                        <span class="access-dot"></span>
                        SECURE ACCESS
                    </span>
                    <span class="access-version">CM / 01</span>
                </div>
                <div class="welcome-icon">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <rect x="2" y="2" width="44" height="44" rx="15"
                            fill="url(#welcomeGradient)" />
                        <path d="M24 11L35 16V24C35 31 30.3 35 24 38
                                 C17.7 35 13 31 13 24V16L24 11Z"
                            stroke="white" stroke-width="1.8"
                            stroke-linejoin="round" />
                        <path d="M19.5 24L22.5 27L29 20.5"
                            stroke="white" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <defs>
                            <linearGradient id="welcomeGradient" x1="2" y1="2"
                                x2="46" y2="46">
                                <stop stop-color="#9333EA" />
                                <stop offset="1" stop-color="#4338CA" />
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <div class="login-heading">
                    <p class="welcome-label">WELCOME BACK</p>
                    <h2>Sign in to your<br>
                        <span>workspace.</span>
                    </h2>
                    <p class="login-description">
                        Enter your credentials to continue to your dashboard.
                    </p>
                </div>
                <?php if ($error !== ''): ?>
                    <div class="error-message" role="alert">
                        <span class="error-icon" aria-hidden="true">!</span>
                        <span>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                <?php endif; ?>
                <form action="index.php" method="POST" class="login-form">
                    <div class="form-group">
                        <label for="email_contact">Email address or contact</label>
                        <div class="input-wrapper">
                            <svg viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="3"
                                    stroke="currentColor" stroke-width="1.6" />
                                <path d="M4 7L12 13L20 7"
                                    stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            <input
                                type="text"
                                id="email_contact"
                                name="email_contact"
                                placeholder="Enter your email or contact"
                                value="<?= htmlspecialchars($emailContact, ENT_QUOTES, 'UTF-8') ?>"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="password-label-row">
                            <label for="password">Password</label>
                            <span class="field-hint">Required</span>
                        </div>
                        <div class="input-wrapper password-wrapper">
                            <svg viewBox="0 0 24 24" fill="none"
                                aria-hidden="true">
                                <rect x="4" y="10" width="16" height="11" rx="3"
                                    stroke="currentColor" stroke-width="1.6" />
                                <path d="M8 10V7C8 4.8 9.8 3 12 3
                                         C14.2 3 16 4.8 16 7V10"
                                    stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                                <circle cx="12" cy="15.5" r="1.2"
                                    fill="currentColor" />
                            </svg>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required>
                            <button
                                type="button"
                                class="password-toggle"
                                id="togglePassword"
                                aria-label="Show password"
                                aria-pressed="false">
                                <svg class="eye-open" viewBox="0 0 24 24"
                                    fill="none" aria-hidden="true">
                                    <path d="M2.5 12S6 5.5 12 5.5
                                             S21.5 12 21.5 12
                                             S18 18.5 12 18.5
                                             S2.5 12 2.5 12Z"
                                        stroke="currentColor"
                                        stroke-width="1.7" />
                                    <circle cx="12" cy="12" r="3"
                                        stroke="currentColor"
                                        stroke-width="1.7" />
                                </svg>
                                <svg class="eye-closed" viewBox="0 0 24 24"
                                    fill="none" aria-hidden="true">
                                    <path d="M3 3L21 21M10.6 5.8
                                             C11.1 5.7 11.5 5.5 12 5.5
                                             C18 5.5 21.5 12 21.5 12
                                             S20 14.8 17.7 16.6
                                             M6.2 6.8C3.8 8.6 2.5 12
                                             2.5 12S6 18.5 12 18.5
                                             C13.2 18.5 14.3 18.2 15.3 17.8"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        stroke-linecap="round"
                                        stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="security-note">
                        <svg viewBox="0 0 24 24" fill="none"
                            aria-hidden="true">
                            <path d="M12 3L20 6V11C20 16 16.7 19.3 12 21
                                     C7.3 19.3 4 16 4 11V6L12 3Z"
                                stroke="currentColor" stroke-width="1.6"
                                stroke-linejoin="round" />
                            <path d="M9 12L11 14L15.5 9.5"
                                stroke="currentColor" stroke-width="1.6"
                                stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <span>Your workspace is protected by secure sign-in.</span>
                    </div>
                    <button type="submit" class="login-button">
                        <span>Access Workspace</span>
                        <svg viewBox="0 0 24 24" fill="none"
                            aria-hidden="true">
                            <path d="M5 12H19M13 6L19 12L13 18"
                                stroke="currentColor" stroke-width="1.8"
                                stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                    </button>
                </form>
                <div class="login-separator">
                    <span></span>
                    <p>AUTHORIZED PERSONNEL ONLY</p>
                    <span></span>
                </div>
                <div class="login-footer">
                    <span class="footer-lock">
                        <svg viewBox="0 0 24 24" fill="none"
                            aria-hidden="true">
                            <rect x="5" y="10" width="14" height="11" rx="3"
                                stroke="currentColor" stroke-width="1.5" />
                            <path d="M8 10V7C8 4.8 9.8 3 12 3
                                     C14.2 3 16 4.8 16 7V10"
                                stroke="currentColor" stroke-width="1.5" />
                        </svg>
                    </span>
                    <p>Private workspace <span>•</span> Secure access</p>
                </div>
            </div>
            <div class="mobile-brand">
                UNSTOPPABLE SOLUTIONS
                <span>CREATIVE MANAGER</span>
            </div>
        </section>
    </main>
    <script>
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');
        togglePassword.addEventListener('click', function() {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            this.classList.toggle('is-visible', isPassword);
            this.setAttribute(
                'aria-label',
                isPassword ? 'Hide password' : 'Show password'
            );
            this.setAttribute('aria-pressed', String(isPassword));
        });
    </script>
</body>
</html> 