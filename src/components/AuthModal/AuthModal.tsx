import React, { useState } from 'react';
import './AuthModal.css';

interface AuthModalProps {
  onSuccess: () => void;
  onClose?: () => void;
  redirectTo?: string;
}

const AuthModal: React.FC<AuthModalProps> = ({ onSuccess, onClose, redirectTo }) => {
  const [isLogin, setIsLogin] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  
  // Login form
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');
  
  // Register form
  const [registerEmail, setRegisterEmail] = useState('');
  const [registerPassword, setRegisterPassword] = useState('');
  const [registerPasswordConfirm, setRegisterPasswordConfirm] = useState('');
  const [registerFirstName, setRegisterFirstName] = useState('');
  const [registerLastName, setRegisterLastName] = useState('');

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      // Crear un formulario oculto para hacer login vía POST (WordPress requiere esto para cookies)
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/wp-login.php';
      form.style.display = 'none';

      const logInput = document.createElement('input');
      logInput.type = 'hidden';
      logInput.name = 'log';
      logInput.value = loginEmail;
      form.appendChild(logInput);

      const pwdInput = document.createElement('input');
      pwdInput.type = 'hidden';
      pwdInput.name = 'pwd';
      pwdInput.value = loginPassword;
      form.appendChild(pwdInput);

      const submitInput = document.createElement('input');
      submitInput.type = 'hidden';
      submitInput.name = 'wp-submit';
      submitInput.value = 'Iniciar sesión';
      form.appendChild(submitInput);

      const redirectInput = document.createElement('input');
      redirectInput.type = 'hidden';
      redirectInput.name = 'redirect_to';
      redirectInput.value = redirectTo || window.location.href;
      form.appendChild(redirectInput);

      const testCookieInput = document.createElement('input');
      testCookieInput.type = 'hidden';
      testCookieInput.name = 'testcookie';
      testCookieInput.value = '1';
      form.appendChild(testCookieInput);

      document.body.appendChild(form);

      // Usar fetch para enviar el formulario y manejar la respuesta
      const formData = new FormData(form);
      const response = await fetch('/wp-login.php', {
        method: 'POST',
        body: formData,
        credentials: 'include',
        redirect: 'manual', // No seguir redirects automáticamente
      });

      document.body.removeChild(form);

      // Verificar si el login fue exitoso
      if (response.type === 'opaqueredirect' || response.status === 302 || response.status === 0) {
        // El login fue exitoso, verificar usuario
        const userResponse = await fetch('/wp-json/wp/v2/users/me', {
          credentials: 'include',
          headers: {
            'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
          },
        });

        if (userResponse.ok) {
          onSuccess();
          // Recargar para obtener el nuevo nonce y user ID
          setTimeout(() => {
            if (redirectTo) {
              window.location.href = redirectTo;
            } else {
              window.location.reload();
            }
          }, 300);
        } else {
          setError('Error al verificar la sesión. Por favor, intenta de nuevo.');
        }
      } else {
        // Leer la respuesta para ver si hay errores
        const text = await response.text();
        if (text.includes('incorrect_password') || text.includes('invalid_username')) {
          setError('Correo electrónico o contraseña incorrectos');
        } else {
          setError('Error al iniciar sesión. Por favor, intenta de nuevo.');
        }
      }
    } catch (err: any) {
      setError(err.message || 'Error al iniciar sesión. Por favor, intenta de nuevo.');
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    // Validaciones
    if (registerPassword !== registerPasswordConfirm) {
      setError('Las contraseñas no coinciden');
      setLoading(false);
      return;
    }

    if (registerPassword.length < 6) {
      setError('La contraseña debe tener al menos 6 caracteres');
      setLoading(false);
      return;
    }

    try {
      const response = await fetch('/wp-json/cna/v1/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
        },
        body: JSON.stringify({
          email: registerEmail,
          password: registerPassword,
          first_name: registerFirstName,
          last_name: registerLastName,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Error al registrar usuario');
      }

      // Si el registro fue exitoso, intentar login automático
      if (data.success) {
        // Auto-login después del registro usando formulario POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/wp-login.php';
        form.style.display = 'none';

        const logInput = document.createElement('input');
        logInput.type = 'hidden';
        logInput.name = 'log';
        logInput.value = registerEmail;
        form.appendChild(logInput);

        const pwdInput = document.createElement('input');
        pwdInput.type = 'hidden';
        pwdInput.name = 'pwd';
        pwdInput.value = registerPassword;
        form.appendChild(pwdInput);

        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'wp-submit';
        submitInput.value = 'Iniciar sesión';
        form.appendChild(submitInput);

        const redirectInput = document.createElement('input');
        redirectInput.type = 'hidden';
        redirectInput.name = 'redirect_to';
        redirectInput.value = redirectTo || window.location.href;
        form.appendChild(redirectInput);

        const testCookieInput = document.createElement('input');
        testCookieInput.type = 'hidden';
        testCookieInput.name = 'testcookie';
        testCookieInput.value = '1';
        form.appendChild(testCookieInput);

        document.body.appendChild(form);

        const formData = new FormData(form);
        const loginResponse = await fetch('/wp-login.php', {
          method: 'POST',
          body: formData,
          credentials: 'include',
          redirect: 'manual',
        });

        document.body.removeChild(form);

        if (loginResponse.type === 'opaqueredirect' || loginResponse.status === 302 || loginResponse.status === 0) {
          // Verificar que el usuario está autenticado
          const userResponse = await fetch('/wp-json/wp/v2/users/me', {
            credentials: 'include',
            headers: {
              'X-WP-Nonce': (window as any).wpApiSettings?.nonce || '',
            },
          });

          if (userResponse.ok) {
            onSuccess();
            setTimeout(() => {
              if (redirectTo) {
                window.location.href = redirectTo;
              } else {
                window.location.reload();
              }
            }, 300);
          } else {
            setError('Registro exitoso. Por favor, inicia sesión.');
            setIsLogin(true);
          }
        } else {
          setError('Registro exitoso. Por favor, inicia sesión.');
          setIsLogin(true);
        }
      }
    } catch (err: any) {
      setError(err.message || 'Error al registrar usuario. Por favor, intenta de nuevo.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="cna-auth-modal-overlay" onClick={onClose}>
      <div className="cna-auth-modal" onClick={(e) => e.stopPropagation()}>
        <div className="cna-auth-modal-header">
          <h3>{isLogin ? 'Iniciar Sesión' : 'Crear Cuenta'}</h3>
          {onClose && (
            <button type="button" className="cna-auth-modal-close" onClick={onClose}>
              ×
            </button>
          )}
        </div>

        <div className="cna-auth-modal-tabs">
          <button
            type="button"
            className={isLogin ? 'active' : ''}
            onClick={() => {
              setIsLogin(true);
              setError(null);
            }}
          >
            Iniciar Sesión
          </button>
          <button
            type="button"
            className={!isLogin ? 'active' : ''}
            onClick={() => {
              setIsLogin(false);
              setError(null);
            }}
          >
            Registrarse
          </button>
        </div>

        {error && (
          <div className="cna-auth-error">{error}</div>
        )}

        {isLogin ? (
          <form onSubmit={handleLogin} className="cna-auth-form">
            <div className="cna-form-group">
              <label htmlFor="login-email">Correo electrónico *</label>
              <input
                id="login-email"
                type="email"
                value={loginEmail}
                onChange={(e) => setLoginEmail(e.target.value)}
                required
                disabled={loading}
                placeholder="tu@email.com"
              />
            </div>

            <div className="cna-form-group">
              <label htmlFor="login-password">Contraseña *</label>
              <input
                id="login-password"
                type="password"
                value={loginPassword}
                onChange={(e) => setLoginPassword(e.target.value)}
                required
                disabled={loading}
                placeholder="••••••••"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="cna-auth-button cna-auth-button-primary"
            >
              {loading ? 'Iniciando sesión...' : 'Iniciar Sesión'}
            </button>

            <p className="cna-auth-footer">
              ¿No tienes cuenta?{' '}
              <button
                type="button"
                className="cna-auth-link"
                onClick={() => setIsLogin(false)}
              >
                Regístrate aquí
              </button>
            </p>
          </form>
        ) : (
          <form onSubmit={handleRegister} className="cna-auth-form">
            <div className="cna-form-group">
              <label htmlFor="register-first-name">Nombre *</label>
              <input
                id="register-first-name"
                type="text"
                value={registerFirstName}
                onChange={(e) => setRegisterFirstName(e.target.value)}
                required
                disabled={loading}
                placeholder="Juan"
              />
            </div>

            <div className="cna-form-group">
              <label htmlFor="register-last-name">Apellido *</label>
              <input
                id="register-last-name"
                type="text"
                value={registerLastName}
                onChange={(e) => setRegisterLastName(e.target.value)}
                required
                disabled={loading}
                placeholder="Pérez"
              />
            </div>

            <div className="cna-form-group">
              <label htmlFor="register-email">Correo electrónico *</label>
              <input
                id="register-email"
                type="email"
                value={registerEmail}
                onChange={(e) => setRegisterEmail(e.target.value)}
                required
                disabled={loading}
                placeholder="tu@email.com"
              />
            </div>

            <div className="cna-form-group">
              <label htmlFor="register-password">Contraseña *</label>
              <input
                id="register-password"
                type="password"
                value={registerPassword}
                onChange={(e) => setRegisterPassword(e.target.value)}
                required
                disabled={loading}
                minLength={6}
                placeholder="Mínimo 6 caracteres"
              />
            </div>

            <div className="cna-form-group">
              <label htmlFor="register-password-confirm">Confirmar Contraseña *</label>
              <input
                id="register-password-confirm"
                type="password"
                value={registerPasswordConfirm}
                onChange={(e) => setRegisterPasswordConfirm(e.target.value)}
                required
                disabled={loading}
                minLength={6}
                placeholder="Repite la contraseña"
              />
            </div>

            <button
              type="submit"
              disabled={loading}
              className="cna-auth-button cna-auth-button-primary"
            >
              {loading ? 'Creando cuenta...' : 'Crear Cuenta'}
            </button>

            <p className="cna-auth-footer">
              ¿Ya tienes cuenta?{' '}
              <button
                type="button"
                className="cna-auth-link"
                onClick={() => setIsLogin(true)}
              >
                Inicia sesión aquí
              </button>
            </p>
          </form>
        )}
      </div>
    </div>
  );
};

export default AuthModal;
