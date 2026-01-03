# Social Login Documentation

## Overview
This implementation allows users to log in using third-party providers via OAuth (Google, Facebook, GitHub, etc.) using Laravel Socialite. This feature supports account creation (first-time login) and account linking (matching by email).

## Endpoints

### 1. Redirect to Provider
Initiates the OAuth flow by redirecting the user to the provider's login page.
- **URL**: `/api/auth/{provider}/redirect`
- **Method**: `GET`
- **Params**: `provider` (google, facebook, github)
- **Response**: 302 Redirect to Provider's Consent Screen.

### 2. Callback from Provider
Handles the callback from the provider after user consent. It creates or updates the user record, generates a Sanctum API token, and redirects the user back to the configured frontend application.
- **URL**: `/api/auth/{provider}/callback`
- **Method**: `GET`
- **Response**: 302 Redirect to `FRONTEND_URL/auth/callback?token={plainTextToken}&user_id={id}`
- **Error Response**: Redirect to `FRONTEND_URL/login?error=social_login_failed`

## Frontend Integration

1.  **Login Button**: Create a link or button that points to the API redirect endpoint.
    ```html
    <a href="http://your-api-url.com/api/auth/google/redirect">Login with Google</a>
    ```

2.  **Callback Page**: Create a route/page in your frontend application at `/auth/callback` (or your preferred route).
    - Retrieve the `token` and `user_id` query parameters from the URL.
    - Store the token (e.g., in localStorage, cookies, or context).
    - Redirect the user to their dashboard or home page.

    **Example (React):**
    ```jsx
    import { useEffect } from 'react';
    import { useNavigate, useSearchParams } from 'react-router-dom';

    const AuthCallback = () => {
        const [searchParams] = useSearchParams();
        const navigate = useNavigate();

        useEffect(() => {
            const token = searchParams.get('token');
            const userId = searchParams.get('user_id');

            if (token) {
                // Store token in localStorage or Auth Context
                localStorage.setItem('auth_token', token);
                
                // Redirect user
                navigate('/dashboard');
            } else {
                // Handle error
                navigate('/login');
            }
        }, [searchParams, navigate]);

        return <div>Processing login...</div>;
    };
    ```

## Configuration

### Environment Variables
Add the following to your `.env` file. ensure `FRONTEND_URL` points to your client application.

```env
FRONTEND_URL=http://localhost:3000

GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URL=http://your-api-url.com/api/auth/google/callback

FACEBOOK_CLIENT_ID=your-facebook-client-id
FACEBOOK_CLIENT_SECRET=your-facebook-client-secret
FACEBOOK_REDIRECT_URL=http://your-api-url.com/api/auth/facebook/callback

GITHUB_CLIENT_ID=your-github-client-id
GITHUB_CLIENT_SECRET=your-github-client-secret
GITHUB_REDIRECT_URL=http://your-api-url.com/api/auth/github/callback
```

### Supported Providers
The current implementation supports Google, Facebook, and GitHub out of the box. To add more providers:
1.  Check [Laravel Socialite Documentation](https://laravel.com/docs/socialite).
2.  Add credentials to `config/services.php` following the existing pattern.
3.  Add environment variables.
