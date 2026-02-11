<?php
// ===== KONFIGURACJA =====
$PASSWORD = 'prywys25';  // <-- ZMIEŃ HASŁO TUTAJ
$SESSION_TIME = 10 * 60;       // 10 minut w sekundach

// Start sesji
session_start();

// Sprawdź czy użytkownik się wylogowuje
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}


$error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $date = date('Y-m-d H:i:s');
    
    if ($_POST['password'] === $PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $status = 'SUCCESS';
    } else {
        $error = true;
        $status = 'FAILED';
    }
    
    // Logowanie do pliku
    $logEntry = sprintf("[%s] IP: %s | Status: %s\n", $date, $ip, $status);
    file_put_contents(__DIR__ . '/log.txt', $logEntry, FILE_APPEND | LOCK_EX);
}




// Sprawdź czy sesja jest ważna
$isAuthenticated = false;
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    if (isset($_SESSION['auth_time']) && (time() - $_SESSION['auth_time']) < $SESSION_TIME) {
        $isAuthenticated = true;
    } else {
        // Sesja wygasła
        session_destroy();
    }
}

// Oblicz pozostały czas sesji
$remainingTime = 0;
if ($isAuthenticated && isset($_SESSION['auth_time'])) {
    $remainingTime = $SESSION_TIME - (time() - $_SESSION['auth_time']);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Propozycje logo – poznajwyszynskiego.pl</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --burgundy: #722F37;
            --burgundy-dark: #5a252c;
            --gold: #c9a227;
            --gold-light: #d4b84a;
            --blue: #4a7eb8;
            --navy: #1a365d;
            --cream: #faf8f5;
            --gray-light: #f5f3f0;
            --gray: #6b7280;
            --text: #2d2d2d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--cream);
            color: var(--text);
            line-height: 1.6;
        }

        /* Password Modal */
        .password-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(26, 54, 93, 0.95);
            backdrop-filter: blur(10px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-modal {
            background: white;
            border-radius: 20px;
            padding: 50px 40px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 80px rgba(0,0,0,0.3);
        }

        .password-modal .lock-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--burgundy) 0%, var(--burgundy-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
        }

        .password-modal .lock-icon svg {
            width: 32px;
            height: 32px;
            color: white;
        }

        .password-modal h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.6rem;
            color: var(--navy);
            margin-bottom: 10px;
        }

        .password-modal p {
            color: var(--gray);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .password-modal input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            text-align: center;
            letter-spacing: 3px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .password-modal input:focus {
            outline: none;
            border-color: var(--burgundy);
            box-shadow: 0 0 0 4px rgba(114, 47, 55, 0.1);
        }

        .password-modal input.error {
            border-color: #e53e3e;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-8px); }
            40%, 80% { transform: translateX(8px); }
        }

        .password-modal button {
            width: 100%;
            padding: 15px 30px;
            background: linear-gradient(135deg, var(--burgundy) 0%, var(--burgundy-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .password-modal button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(114, 47, 55, 0.3);
        }

        .password-modal .error-message {
            color: #e53e3e;
            font-size: 0.85rem;
            margin-top: 15px;
        }

        /* Session timer */
        .session-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--navy);
            color: white;
            padding: 8px 20px;
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
        }

        .session-bar a {
            color: white;
            opacity: 0.7;
            text-decoration: none;
            font-size: 0.75rem;
        }

        .session-bar a:hover {
            opacity: 1;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--burgundy) 0%, var(--burgundy-dark) 100%);
            color: white;
            padding: 60px 20px;
            padding-top: 100px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M30 5v20M20 15h20' stroke='%23ffffff' stroke-width='1' opacity='0.05' fill='none'/%3E%3C/svg%3E");
            opacity: 0.3;
        }

        header h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            letter-spacing: 1px;
        }

        header .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 300;
            position: relative;
        }

        header .domain {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 30px;
            background: rgba(255,255,255,0.15);
            border-radius: 30px;
            font-size: 1.2rem;
            font-weight: 500;
            letter-spacing: 2px;
            position: relative;
            backdrop-filter: blur(5px);
        }

        /* Navigation */
        nav {
            background: white;
            padding: 15px 20px;
            position: sticky;
            top: 40px;
            z-index: 100;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        }

        nav ul {
            list-style: none;
            display: flex;
            justify-content: center;
            gap: 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        nav a {
            text-decoration: none;
            color: var(--text);
            font-weight: 500;
            font-size: 0.95rem;
            padding: 8px 0;
            position: relative;
            transition: color 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        nav a .nav-icon {
            display: none;
            width: 24px;
            height: 24px;
        }

        nav a .nav-text {
            display: inline;
        }

        nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--burgundy);
            transition: width 0.3s;
        }

        nav a:hover {
            color: var(--burgundy);
        }

        nav a:hover::after {
            width: 100%;
        }

        /* Mobile navigation */
        @media (max-width: 600px) {
            nav {
                padding: 12px 15px;
            }

            nav ul {
                gap: 0;
                justify-content: space-around;
                width: 100%;
            }

            nav a {
                flex-direction: column;
                gap: 4px;
                padding: 8px 12px;
                font-size: 0.7rem;
                text-align: center;
            }

            nav a .nav-icon {
                display: block;
            }

            nav a .nav-text {
                display: block;
                font-size: 0.65rem;
                font-weight: 400;
                color: var(--gray);
            }

            nav a::after {
                display: none;
            }

            nav a:hover .nav-text,
            nav a:hover {
                color: var(--burgundy);
            }
        }

        /* Main content */
        main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 60px 20px;
        }

        section {
            margin-bottom: 80px;
        }

        .section-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-header h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 2.2rem;
            color: var(--burgundy);
            margin-bottom: 15px;
        }

        .section-header p {
            color: var(--gray);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Logo cards */
        .logo-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
        }

        @media (max-width: 900px) {
            .logo-grid {
                grid-template-columns: 1fr;
            }
        }

        .logo-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 30px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .logo-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.12);
        }

        .logo-preview {
            background: white;
            padding: 50px 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 250px;
            border-bottom: 1px solid #f0f0f0;
        }

        .logo-preview.dark {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }

        .logo-preview img {
            max-width: 100%;
            max-height: 180px;
            object-fit: contain;
        }

        .logo-info {
            padding: 25px 30px;
        }

        .logo-info h3 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.3rem;
            color: var(--navy);
            margin-bottom: 10px;
        }

        .logo-info p {
            color: var(--gray);
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .logo-tag {
            display: inline-block;
            padding: 5px 12px;
            background: var(--gray-light);
            border-radius: 20px;
            font-size: 0.8rem;
            color: var(--burgundy);
            font-weight: 500;
            margin-top: 15px;
        }

        .logo-tag.recommended {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            color: white;
        }

        /* Icons section */
        .icons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }

        .icon-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            transition: transform 0.3s;
        }

        .icon-card:hover {
            transform: translateY(-3px);
        }

        .icon-card img {
            max-width: 150px;
            max-height: 150px;
            margin-bottom: 20px;
        }

        .icon-card h4 {
            font-size: 1rem;
            color: var(--navy);
            margin-bottom: 8px;
        }

        .icon-card p {
            font-size: 0.85rem;
            color: var(--gray);
        }

        /* Video section */
        .video-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 30px rgba(0,0,0,0.08);
        }

        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            background: #000;
        }

        .video-wrapper video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .video-placeholder {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--navy) 0%, #0f172a 100%);
            color: white;
        }

        .video-placeholder svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.8;
        }

        .video-placeholder p {
            font-size: 1.1rem;
            opacity: 0.7;
        }

        .video-info {
            padding: 25px 30px;
        }

        .video-info h3 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 1.3rem;
            color: var(--navy);
            margin-bottom: 10px;
        }

        .video-info p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Color palette */
        .palette {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 30px;
        }

        .color-swatch {
            text-align: center;
        }

        .color-swatch .swatch {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            margin-bottom: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .color-swatch .name {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text);
        }

        .color-swatch .hex {
            font-size: 0.75rem;
            color: var(--gray);
            font-family: monospace;
        }

        /* Footer */
        footer {
            background: var(--navy);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }

        footer p {
            opacity: 0.7;
            font-size: 0.9rem;
        }

        footer .date {
            margin-top: 10px;
            font-size: 0.85rem;
            opacity: 0.5;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-card, .icon-card, .video-container {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .logo-card:nth-child(1) { animation-delay: 0.1s; }
        .logo-card:nth-child(2) { animation-delay: 0.2s; }

        /* Print styles */
        @media print {
            nav, footer, .session-bar { display: none; }
            .logo-card { break-inside: avoid; }
        }
    </style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
<!-- Password Modal -->
<div class="password-overlay">
    <div class="password-modal">
        <div class="lock-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>
        <h2>Strona chroniona</h2>
        <p>Wprowadź hasło</p>
        <form method="POST">
            <input type="password" name="password" placeholder="••••••••" autocomplete="off" autofocus class="<?php echo $error ? 'error' : ''; ?>">
            <?php if ($error): ?>
                <p class="error-message">Nieprawidłowe hasło. Spróbuj ponownie.</p>
            <?php endif; ?>
            <button type="submit">Wejdź</button>
        </form>
    </div>
</div>

<?php else: ?>

<!-- Session Bar -->
<div class="session-bar">
    <span>🔓 Sesja wygaśnie za: <strong id="countdown"><?php echo floor($remainingTime / 60) . ':' . str_pad($remainingTime % 60, 2, '0', STR_PAD_LEFT); ?></strong></span>
    <a href="?logout=1">Wyloguj się</a>
</div>

<header>
    <h1>Propozycje identyfikacji wizualnej</h1>
    <p class="subtitle">Projekt: Inteligentna wyszukiwarka tekstów Prymasa Wyszyńskiego</p>
    <span class="domain">poznajwyszynskiego.pl</span>
</header>

<nav>
    <ul>
        <li>
            <a href="#logo-glowne">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <span class="nav-text">Logo główne</span>
            </a>
        </li>
        <li>
            <a href="#elementy">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                    <polyline points="2 17 12 22 22 17"></polyline>
                    <polyline points="2 12 12 17 22 12"></polyline>
                </svg>
                <span class="nav-text">Elementy</span>
            </a>
        </li>
        <li>
            <a href="#wideo">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
                <span class="nav-text">Wideo</span>
            </a>
        </li>
        <li>
            <a href="#kolory">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="13.5" cy="6.5" r="2.5"></circle>
                    <circle cx="17.5" cy="10.5" r="2.5"></circle>
                    <circle cx="8.5" cy="7.5" r="2.5"></circle>
                    <circle cx="6.5" cy="12.5" r="2.5"></circle>
                    <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"></path>
                </svg>
                <span class="nav-text">Kolory</span>
            </a>
        </li>
    </ul>
</nav>

<main>

    <!-- Logo główne -->
    <section id="logo-glowne">
        <div class="section-header">
            <h2>Propozycje logo</h2>
            <p>Dwa warianty głównego logotypu – z portretem Prymasa oraz w wersji symbolicznej z mitrą i księgą</p>
        </div>

        <div class="logo-grid">
            
            <div class="logo-card">
                <div class="logo-preview">
                    <img src="logo_PW_pl.png" alt="Logo z portretem Prymasa Wyszyńskiego">
                </div>
                <div class="logo-info">
                    <h3>Wariant A – Z portretem Prymasa</h3>
                    <p>Stylizowany portret Kardynała Stefana Wyszyńskiego w charakterystycznej pozie zamyślenia. Podkreśla osobisty, ludzki wymiar projektu – bezpośrednie „poznawanie" postaci Prymasa.</p>
                    <span class="logo-tag recommended">✦ Rekomendowany</span>
                </div>
            </div>

            <div class="logo-card">
                <div class="logo-preview">
                    <img src="logo_PW_2_pl.png" alt="Logo z mitrą i księgą">
                </div>
                <div class="logo-info">
                    <h3>Wariant B – Symboliczny</h3>
                    <p>Alternatywna wersja z symbolami: mitra biskupia (godność prymasowska) oraz otwarta księga (teksty, nauczanie). Bardziej uniwersalny i łatwiejszy do skalowania na małe formaty.</p>
                    <span class="logo-tag">Wersja alternatywna</span>
                </div>
            </div>

        </div>
    </section>

    <!-- Elementy graficzne -->
    <section id="elementy">
        <div class="section-header">
            <h2>Elementy graficzne</h2>
            <p>Samodzielne ikony do wykorzystania jako favicon, avatar, znak wodny</p>
        </div>

        <div class="icons-grid">
            
            <div class="icon-card">
                <img src="logo_PW_bez_obramowania.png" alt="Portret Prymasa - ikona">
                <h4>Portret – wersja monochromatyczna</h4>
                <p>Do użycia jako favicon, avatar w social media, znak wodny na dokumentach</p>
            </div>

            <div class="icon-card">
                <img src="logo2_PW.png" alt="Mitra z księgą - ikona">
                <h4>Symbol mitry z księgą</h4>
                <p>Uproszczona ikona do zastosowań, gdzie portret byłby nieczytelny (małe rozmiary)</p>
            </div>

        </div>
    </section>

    <!-- Materiał wideo -->
    <section id="wideo">
        <div class="section-header">
            <h2>Materiał promocyjny</h2>
            <p>Animacja / spot wideo do wykorzystania w kampanii promocyjnej projektu</p>
        </div>

        <div class="video-container">
            <div class="video-wrapper">
                <video controls poster="logo_PW_bez_obramowania.png">
                    <source src="wyszynski.mp4" type="video/mp4">
                    <div class="video-placeholder">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                        <p>wyszynski.mp4</p>
                    </div>
                </video>
            </div>
            <div class="video-info">
                <h3>Spot promocyjny</h3>
                <p>Materiał wideo do wykorzystania na stronie głównej, w social media oraz podczas prezentacji projektu.</p>
            </div>
        </div>
    </section>

    <!-- Kolorystyka -->
    <section id="kolory">
        <div class="section-header">
            <h2>Paleta kolorów</h2>
            <p>Spójna kolorystyka nawiązująca do szat liturgicznych i godności prymasowskiej</p>
        </div>

        <div class="palette">
            <div class="color-swatch">
                <div class="swatch" style="background: #722F37;"></div>
                <div class="name">Burgund</div>
                <div class="hex">#722F37</div>
            </div>
            <div class="color-swatch">
                <div class="swatch" style="background: #4a7eb8;"></div>
                <div class="name">Błękit</div>
                <div class="hex">#4A7EB8</div>
            </div>
            <div class="color-swatch">
                <div class="swatch" style="background: #1a365d;"></div>
                <div class="name">Granat</div>
                <div class="hex">#1A365D</div>
            </div>
            <div class="color-swatch">
                <div class="swatch" style="background: #c9a227;"></div>
                <div class="name">Złoto</div>
                <div class="hex">#C9A227</div>
            </div>
            <div class="color-swatch">
                <div class="swatch" style="background: #faf8f5;"></div>
                <div class="name">Kość słoniowa</div>
                <div class="hex">#FAF8F5</div>
            </div>
        </div>
    </section>

</main>

<footer>
    <p>Propozycje identyfikacji wizualnej dla projektu <strong>poznajwyszynskiego.pl</strong></p>
    <p class="date">Przygotował: Michał Zboina MIKOM grudzień 2025</p>
</footer>

<script>
    // Smooth scroll for navigation
    document.querySelectorAll('nav a').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Countdown timer
    let remaining = <?php echo $remainingTime; ?>;
    const countdownEl = document.getElementById('countdown');
    
    setInterval(() => {
        remaining--;
        if (remaining <= 0) {
            window.location.reload();
        } else {
            const min = Math.floor(remaining / 60);
            const sec = remaining % 60;
            countdownEl.textContent = min + ':' + sec.toString().padStart(2, '0');
        }
    }, 1000);

    // Intersection Observer for animations
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.logo-card, .icon-card, .video-container').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
        observer.observe(el);
    });
</script>

<?php endif; ?>

</body>
</html>