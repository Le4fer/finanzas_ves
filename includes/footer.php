</main>
</div>

<!-- Sistema de Notificaciones Temporales -->
<script>
    class FlashNotificationSystem {
        constructor() {
            this.container = null;
            this.init();
        }

        init() {
            // Obtener o crear contenedor
            this.container = document.getElementById('flash-notification-container');
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.className = 'flash-notification-container';
                this.container.id = 'flash-notification-container';
                document.body.appendChild(this.container);
            }

            // Mostrar notificaciones de sesión al cargar
            this.showSessionNotifications();
        }

        show(type, title, message, duration = 4000) {
            // Crear elemento de notificación
            const notification = document.createElement('div');
            notification.className = `flash-notification notification-${type}`;

            // Formatear el mensaje si contiene listas
            const formattedMessage = this.formatMessage(message);

            notification.innerHTML = `
                <div class="notification-header">
                    <span>${title}</span>
                    <button class="notification-close" onclick="window.flashNotification.hide(this.closest('.flash-notification'))">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="notification-body">
                    ${formattedMessage}
                </div>
            `;

            // Agregar al contenedor
            this.container.appendChild(notification);

            // Auto-eliminar después del tiempo especificado (4 segundos)
            setTimeout(() => {
                this.hide(notification);
            }, duration);

            return notification;
        }

        formatMessage(message) {
            // Si el mensaje tiene formato de lista con •
            if (message.includes('•')) {
                const items = message.split('•').filter(item => item.trim());
                if (items.length > 1) {
                    return `<ul>${items.map(item => `<li>${item.trim()}</li>`).join('')}</ul>`;
                }
            }
            return message;
        }

        hide(notification) {
            if (notification && notification.parentNode) {
                // Agregar clase para animación de salida
                notification.classList.add('hiding');

                // Eliminar después de la animación
                setTimeout(() => {
                    if (notification.parentNode === this.container) {
                        this.container.removeChild(notification);
                    }
                }, 300); // Tiempo de la animación
            }
        }

        showSessionNotifications() {
            // Verificar si hay notificaciones en la sesión PHP
            // Esto será manejado por código PHP que inyectaremos más abajo
        }

        // Función para mostrar notificación desde JavaScript
        showSuccess(title, message) {
            return this.show('success', title, message);
        }

        showError(title, message) {
            return this.show('error', title, message);
        }

        showWarning(title, message) {
            return this.show('warning', title, message);
        }

        showInfo(title, message) {
            return this.show('info', title, message);
        }
    }

    // Inicializar el sistema cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function () {
        window.flashNotification = new FlashNotificationSystem();

        // Función global para mostrar notificaciones desde cualquier parte
        window.showNotification = function (type, title, message, duration = 4000) {
            if (window.flashNotification) {
                return window.flashNotification.show(type, title, message, duration);
            }
            return null;
        };
    });
</script>

<!-- Código PHP para mostrar notificaciones de sesión -->
<?php
// Mostrar notificaciones almacenadas en sesión
if (isset($_SESSION['flash_notification'])) {
    $notification = $_SESSION['flash_notification'];
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () {
                if (window.flashNotification) {
                    window.flashNotification.show(
                        '<?= $notification['type'] ?>',
                        '<?= addslashes($notification['title']) ?>',
                        `<?= addslashes($notification['message']) ?>`,
                        4000 // 4 segundos
                    );
                }
            }, 300); // Pequeño delay para asegurar que el DOM esté listo
        });
    </script>
    <?php
    // Limpiar la notificación después de mostrarla
    unset($_SESSION['flash_notification']);
}
?>

<!-- Script para mostrar mensajes de éxito/error de Bootstrap -->
<script>
    // Interceptar y mostrar mensajes de Bootstrap como notificaciones
    document.addEventListener('DOMContentLoaded', function () {
        // Buscar alertas de Bootstrap en la página
        const bootstrapAlerts = document.querySelectorAll('.alert');
        bootstrapAlerts.forEach(function (alert) {
            // Determinar tipo de alerta por clase
            let type = 'info';
            if (alert.classList.contains('alert-success')) type = 'success';
            if (alert.classList.contains('alert-danger')) type = 'error';
            if (alert.classList.contains('alert-warning')) type = 'warning';
            if (alert.classList.contains('alert-info')) type = 'info';

            // Extraer título y mensaje
            const title = alert.querySelector('.alert-heading') ?
                alert.querySelector('.alert-heading').textContent :
                (type === 'success' ? '✅ Éxito' :
                    type === 'error' ? '❌ Error' :
                        type === 'warning' ? '⚠️ Advertencia' : 'ℹ️ Información');

            let message = '';
            // Si hay elementos de lista, convertirlos a formato
            const listItems = alert.querySelectorAll('li');
            if (listItems.length > 0) {
                message = Array.from(listItems).map(li => '• ' + li.textContent.trim()).join('');
            } else {
                // Remover el título si existe
                const tempAlert = alert.cloneNode(true);
                const heading = tempAlert.querySelector('.alert-heading');
                if (heading) heading.remove();
                message = tempAlert.textContent.trim();
            }

            // Mostrar como notificación temporal
            if (message.trim() !== '') {
                setTimeout(function () {
                    if (window.flashNotification) {
                        window.flashNotification.show(type, title, message);
                    }
                }, 500);
            }

            // Ocultar la alerta original después de un tiempo
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 500);
                }
            }, 1000);
        });
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>