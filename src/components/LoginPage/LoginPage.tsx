import React, { useState } from 'react';
import { loginUser, registerUser } from '../../services/api';
import './LoginPage.css';

interface LoginPageProps {
  redirectTo: string;
}

const getLostPasswordUrl = (): string => {
  const settings = (window as { wpApiSettings?: { lostPasswordUrl?: string } }).wpApiSettings;
  return settings?.lostPasswordUrl || '/wp-login.php?action=lostpassword';
};

const LoginPage: React.FC<LoginPageProps> = ({ redirectTo }) => {
  const [isLogin, setIsLogin] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');

  const [registerEmail, setRegisterEmail] = useState('');
  const [registerPassword, setRegisterPassword] = useState('');
  const [registerPasswordConfirm, setRegisterPasswordConfirm] = useState('');
  const [registerFirstName, setRegisterFirstName] = useState('');
  const [registerLastName, setRegisterLastName] = useState('');
  const [honeypot, setHoneypot] = useState('');

  const goAfterAuth = () => {
    window.location.href = redirectTo;
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      await loginUser({
        email: loginEmail,
        password: loginPassword,
        remember: true,
        website: honeypot,
      });
      goAfterAuth();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Error al iniciar sesión. Por favor, intenta de nuevo.';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    if (registerPassword !== registerPasswordConfirm) {
      setError('Las contraseñas no coinciden');
      setLoading(false);
      return;
    }

    if (registerPassword.length < 10) {
      setError('La contraseña debe tener al menos 10 caracteres');
      setLoading(false);
      return;
    }

    if (!/[a-zA-Z]/.test(registerPassword) || !/[0-9]/.test(registerPassword)) {
      setError('La contraseña debe contener al menos una letra y un número');
      setLoading(false);
      return;
    }

    try {
      await registerUser({
        email: registerEmail,
        password: registerPassword,
        first_name: registerFirstName,
        last_name: registerLastName,
        website: honeypot,
      });

      await loginUser({
        email: registerEmail,
        password: registerPassword,
        remember: true,
      });
      goAfterAuth();
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Error al registrar usuario. Por favor, intenta de nuevo.';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="cna-login-page">
      <div className="cna-login-page__shell">
        <p className="cna-login-page__intro">
          {isLogin
            ? 'Accede con tu correo o usuario para continuar con tu suscripción.'
            : 'Crea tu cuenta para suscribirte a productos orgánicos de La Canasta Campesina.'}
        </p>

        <div className="cna-login-page__tabs" role="tablist" aria-label="Tipo de acceso">
          <button
            type="button"
            role="tab"
            aria-selected={isLogin}
            className={`cna-login-page__tab${isLogin ? ' cna-login-page__tab--active' : ''}`}
            onClick={() => {
              setIsLogin(true);
              setError(null);
            }}
          >
            Iniciar sesión
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={!isLogin}
            className={`cna-login-page__tab${!isLogin ? ' cna-login-page__tab--active' : ''}`}
            onClick={() => {
              setIsLogin(false);
              setError(null);
            }}
          >
            Registrarse
          </button>
        </div>

        {error && (
          <div className="cna-login-page__error" role="alert">
            {error}
          </div>
        )}

        {isLogin ? (
          <form onSubmit={handleLogin} className="cna-login-page__form">
            <div style={{ position: 'absolute', left: '-9999px', opacity: 0, height: 0, overflow: 'hidden' }} aria-hidden="true">
              <label htmlFor="login-website">Website</label>
              <input id="login-website" type="text" name="website" tabIndex={-1} autoComplete="off" />
            </div>

            <div className="cna-login-page__field">
              <label htmlFor="login-email">
                Correo electrónico o usuario <span>*</span>
              </label>
              <input
                id="login-email"
                type="text"
                value={loginEmail}
                onChange={(e) => setLoginEmail(e.target.value)}
                required
                disabled={loading}
                placeholder="tu@email.com o nombre de usuario"
                autoComplete="username"
              />
            </div>

            <div className="cna-login-page__field">
              <label htmlFor="login-password">
                Contraseña <span>*</span>
              </label>
              <input
                id="login-password"
                type="password"
                value={loginPassword}
                onChange={(e) => setLoginPassword(e.target.value)}
                required
                disabled={loading}
                placeholder="Tu contraseña"
                autoComplete="current-password"
              />
            </div>

            <button type="submit" disabled={loading} className="cna-login-page__submit">
              {loading ? 'Iniciando sesión…' : 'Iniciar sesión'}
            </button>

            <p className="cna-login-page__footer">
              <a href={getLostPasswordUrl()}>¿Olvidaste tu contraseña?</a>
            </p>
          </form>
        ) : (
          <form onSubmit={handleRegister} className="cna-login-page__form">
            <div style={{ position: 'absolute', left: '-9999px', opacity: 0, height: 0, overflow: 'hidden' }} aria-hidden="true">
              <label htmlFor="register-website">Website</label>
              <input
                id="register-website"
                type="text"
                name="website"
                tabIndex={-1}
                autoComplete="off"
                value={honeypot}
                onChange={(e) => setHoneypot(e.target.value)}
              />
            </div>

            <div className="cna-login-page__row">
              <div className="cna-login-page__field">
                <label htmlFor="register-first-name">
                  Nombre <span>*</span>
                </label>
                <input
                  id="register-first-name"
                  type="text"
                  value={registerFirstName}
                  onChange={(e) => setRegisterFirstName(e.target.value)}
                  required
                  disabled={loading}
                  placeholder="Juan"
                  autoComplete="given-name"
                />
              </div>
              <div className="cna-login-page__field">
                <label htmlFor="register-last-name">
                  Apellido <span>*</span>
                </label>
                <input
                  id="register-last-name"
                  type="text"
                  value={registerLastName}
                  onChange={(e) => setRegisterLastName(e.target.value)}
                  required
                  disabled={loading}
                  placeholder="Pérez"
                  autoComplete="family-name"
                />
              </div>
            </div>

            <div className="cna-login-page__field">
              <label htmlFor="register-email">
                Correo electrónico <span>*</span>
              </label>
              <input
                id="register-email"
                type="email"
                value={registerEmail}
                onChange={(e) => setRegisterEmail(e.target.value)}
                required
                disabled={loading}
                placeholder="tu@email.com"
                autoComplete="email"
              />
            </div>

            <div className="cna-login-page__field">
              <label htmlFor="register-password">
                Contraseña <span>*</span>
              </label>
              <input
                id="register-password"
                type="password"
                value={registerPassword}
                onChange={(e) => setRegisterPassword(e.target.value)}
                required
                disabled={loading}
                minLength={10}
                placeholder="Mínimo 10 caracteres"
                autoComplete="new-password"
              />
            </div>

            <div className="cna-login-page__field">
              <label htmlFor="register-password-confirm">
                Confirmar contraseña <span>*</span>
              </label>
              <input
                id="register-password-confirm"
                type="password"
                value={registerPasswordConfirm}
                onChange={(e) => setRegisterPasswordConfirm(e.target.value)}
                required
                disabled={loading}
                minLength={10}
                placeholder="Repite tu contraseña"
                autoComplete="new-password"
              />
            </div>

            <p className="cna-login-page__hint">
              Usa al menos 10 caracteres, incluyendo letras y números.
            </p>

            <button type="submit" disabled={loading} className="cna-login-page__submit">
              {loading ? 'Creando cuenta…' : 'Crear cuenta'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
};

export default LoginPage;
