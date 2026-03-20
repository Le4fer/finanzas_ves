<?php require 'auth.php'; ?>
<?php // Cargar helpers comunes (CSRF) para que los formularios en las vistas puedan usar csrf_input()
require_once __DIR__ . '/../config/csrf.php'; ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Dashboard - Finanzas Personales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* VARIABLES ORIGINALES */
        .ms-250 {
            margin-left: 250px;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            position: fixed;
            background-color: #113a63ff;
        }

        .sidebar .nav-link {
            color: #adb5bd;
            padding: 12px 15px;
            margin: 2px 0;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .sidebar .nav-link:hover {
            color: #fff;
            background-color: #495057;
        }

        .sidebar .nav-link.active {
            color: #fff;
            background-color: #0d6efd;
        }

        .sidebar .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
            background-color: #f8f9fa;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
            }
        }

        /* MEJORAS VISUALES SIN CAMBIAR ESTRUCTURA */

        /* 1. HEADER DEL SIDEBAR MEJORADO */
        .sidebar-header {
            padding: 25px 20px;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h5 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            letter-spacing: 0.5px;
        }

        .sidebar-header h5 i {
            margin-right: 10px;
            color: #4facfe;
        }

        /* 2. PERFIL DE USUARIO MEJORADO (ARRIBA DEL MENÚ) */
        .user-profile-top {
            padding: 20px 15px;
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .user-avatar-small {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 1.2rem;
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .user-name-top {
            font-size: 0.9rem;
            color: white;
            font-weight: 600;
            margin-bottom: 3px;
        }

        .user-email-top {
            font-size: 0.75rem;
            color: #adb5bd;
        }

        /* 3. MEJORAS EN EL MENÚ NAVEGACIÓN (MISMA ESTRUCTURA) */
        .sidebar .nav-link {
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .sidebar .nav-link:hover {
            border-left-color: #4facfe;
            transform: translateX(5px);
            background: linear-gradient(90deg, rgba(79, 172, 254, 0.1), rgba(79, 172, 254, 0.05));
        }

        .sidebar .nav-link.active {
            border-left-color: #4facfe;
            background: linear-gradient(90deg, rgba(13, 110, 253, 0.9), rgba(13, 110, 253, 0.7));
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }

        .sidebar .nav-link i {
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover i {
            transform: scale(1.1);
            color: #4facfe;
        }

        .sidebar .nav-link.active i {
            color: white;
            transform: scale(1.1);
        }

        /* 4. BOTÓN LOGOUT MEJORADO */
        .logout-section {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: 10px;
            padding-top: 10px;
        }

        .logout-section .nav-link {
            color: #ff6b6b;
        }

        .logout-section .nav-link:hover {
            background: linear-gradient(90deg, rgba(255, 107, 107, 0.1), rgba(255, 107, 107, 0.05));
            border-left-color: #ff6b6b;
        }

        /* 5. HEADER DE PÁGINA MEJORADO */
        .page-header {
            background: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-left: 5px solid #4facfe;
            position: relative;
            /* Allow dropdowns inside header to overflow and be visible */
            overflow: visible;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #4facfe, #00f2fe);
        }

        .page-header h2 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 1.8rem;
            position: relative;
            padding-bottom: 10px;
        }

        .page-header h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: #4facfe;
            border-radius: 2px;
        }

        .page-header .subtitle {
            color: #6c757d;
        }

        /* Fix dropdown visibility inside page header - ensure it appears above other content */
        .page-header .dropdown-menu,
        .page-header .dropdown-meni.dropdown-menu-end.show,
        .dropdown-meni.dropdown-menu-end.show,
        .page-header .dropdown-menu.show {
            position: absolute !important;
            z-index: 9999 !important;
        }

        /* ocultar inicialmente */
        .page-header .dropdown-menu {
            display: none;
        }

        .page-header .dropdown-menu.show {
            display: block !important;
        }

        font-size: 1rem;
        margin-bottom: 0;
        font-weight: 400;
        }

        /* 6. DROPDOWN DE USUARIO MEJORADO */
        .user-dropdown .dropdown-toggle {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 16px;
            color: #495057;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .user-dropdown .dropdown-toggle:hover {
            background: #f8f9fa;
            border-color: #4facfe;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .user-dropdown .dropdown-toggle i {
            color: #4facfe;
        }

        .dropdown-menu {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        /* 7. NOTIFICACIONES FLASH (MANTENER ORIGINAL CON MEJORAS) */
        .flash-notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            max-width: 400px;
        }

        .flash-notification {
            background: white;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            margin-bottom: 15px;
            overflow: hidden;
            animation: slideInRight 0.3s ease-out;
            transition: all 0.3s ease;
            max-width: 400px;
        }

        .flash-notification.hiding {
            animation: slideOutRight 0.3s ease-out forwards;
        }

        .notification-header {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            color: white;
        }

        .notification-success .notification-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .notification-error .notification-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .notification-warning .notification-header {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: #212529;
        }

        .notification-info .notification-header {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        }

        .notification-body {
            padding: 15px 20px;
            background: white;
            color: #333;
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .notification-close {
            background: none;
            border: none;
            color: inherit;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            line-height: 1;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .notification-close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        @media (max-width: 768px) {
            .flash-notification-container {
                left: 20px;
                right: 20px;
                max-width: none;
            }

            .flash-notification {
                max-width: none;
            }
        }

        /* 8. ANIMACIONES SUAVES */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .main-content>* {
            animation: fadeIn 0.4s ease-out;
        }

        /* 9. SCROLLBAR PERSONALIZADO */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    </style>
</head>

<body>
    <!-- Contenedor para notificaciones -->
    <div class="flash-notification-container" id="flash-notification-container"></div>

    <div class="d-flex">
        <!-- Sidebar - ESTRUCTURA ORIGINAL CON MEJORAS -->
        <nav class="sidebar text-white">
            <!-- HEADER DEL SIDEBAR (TÍTULO) -->
            <div class="sidebar-header">
                <h5 class="text-center mb-0 text-white">
                    <i class="fas fa-chart-line me-2"></i>Finanzas Personales
                </h5>
            </div>

            <!-- PERFIL DE USUARIO (ARRIBA DEL MENÚ) -->
            <div class="user-profile-top">
                <div class="user-avatar-small">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-name-top"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Oscar') ?></div>
                <div class="user-email-top">
                    <?= htmlspecialchars($_SESSION['user_email'] ?? 'oscarzbranoivan@gmail.com') ?>
                </div>
            </div>

            <!-- MENÚ DE NAVEGACIÓN (ESTRUCTURA ORIGINAL) -->
            <ul class="nav flex-column p-3">
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>"
                        href="dashboard.php">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'incomes.php' ? 'active' : '' ?>"
                        href="incomes.php">
                        <i class="fas fa-plus-circle"></i> Ingresos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'add_expense.php' ? 'active' : '' ?>"
                        href="add_expense.php">
                        <i class="fas fa-plus"></i> Nuevo Gasto
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'expenses.php' ? 'active' : '' ?>"
                        href="expenses.php">
                        <i class="fas fa-list"></i> Lista de Gastos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : '' ?>"
                        href="categories.php">
                        <i class="fas fa-tags"></i> Categorías
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'budgets.php' ? 'active' : '' ?>"
                        href="budgets.php">
                        <i class="fas fa-chart-pie"></i> Presupuestos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white <?= basename($_SERVER['PHP_SELF']) == 'export.php' ? 'active' : '' ?>"
                        href="export.php">
                        <i class="fas fa-file-csv"></i> Exportar
                    </a>
                </li>

                <!-- LOGOUT (SEPARADO) -->
                <li class="nav-item logout-section">
                    <a class="nav-link text-white" href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Content - ESTRUCTURA ORIGINAL -->
        <main class="main-content">
            <!-- HEADER DE PÁGINA MEJORADO -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">
                            <?php
                            $page_titles = [
                                'dashboard.php' => 'Dashboard',
                                'incomes.php' => 'Gestión de Ingresos',
                                'add_expense.php' => 'Registrar Nuevo Gasto',
                                'expenses.php' => 'Lista de Gastos',
                                'categories.php' => 'Gestión de Categorías',
                                'budgets.php' => 'Presupuestos',
                                'export.php' => 'Exportar Datos'
                            ];
                            $current_page = basename($_SERVER['PHP_SELF']);
                            echo $page_titles[$current_page] ?? 'Finanzas Personales';
                            ?>
                        </h2>
                        <p class="subtitle mb-0">
                            <?php
                            $page_subtitles = [
                                'dashboard.php' => 'Resumen completo de tu situación financiera',
                                'incomes.php' => 'Administra y controla tus ingresos mensuales',
                                'add_expense.php' => 'Registra y clasifica nuevos gastos',
                                'expenses.php' => 'Consulta y gestiona todos tus gastos',
                                'categories.php' => 'Organiza tus gastos por categorías',
                                'budgets.php' => 'Controla tus límites de gasto por categoría',
                                'export.php' => 'Exporta tus datos para análisis externo'
                            ];
                            echo $page_subtitles[$current_page] ?? 'Sistema de gestión financiera personal';
                            ?>
                        </p>
                    </div>
                    <div class="user-dropdown">
                        <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-2"></i>
                            Mi Cuenta
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-muted">Conectado como</span></li>
                            <li><span
                                    class="dropdown-item-text fw-bold"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Oscar') ?></span>
                            </li>
                            <li><span
                                    class="dropdown-item-text small text-muted"><?= htmlspecialchars($_SESSION['user_email'] ?? 'oscarzbranoivan@gmail.com') ?></span>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>Configuración</a></li>
                            <li><a class="dropdown-item" href="logout.php"><i
                                        class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Page specific content will be inserted here -->