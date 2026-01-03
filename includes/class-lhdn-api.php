<?php
/**
 * LHDN API Client
 */

if (!defined('ABSPATH')) exit;

class LHDN_API {
    
    /**
     * Get OAuth token
     * 
     * @param bool $force_new If true, forces a new token request even if cached token exists
     * @return string|false Access token or false on error
     */
    public function get_token($force_new = false) {
        global $wpdb;
        $table = $wpdb->prefix . "lhdn_tokens";
        $now = current_time('mysql');
        $token_refresh_lock = 'lhdn_token_refresh_lock';

        if (!$force_new) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE expires_at > %s ORDER BY id DESC LIMIT 1", $now));

            if ($row) {
                LHDN_Logger::log("Using cached OAuth token");
                return $row->access_token;
            }
        }

        // If forcing new token, check for refresh lock to prevent concurrent refreshes
        if ($force_new) {
            // Check if another process is already refreshing the token
            if (get_transient($token_refresh_lock)) {
                LHDN_Logger::log("Token refresh already in progress, using cached token");
                // Wait a moment for the refresh to complete
                sleep(1);
                // Try to get the newly refreshed token
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE expires_at > %s ORDER BY id DESC LIMIT 1", $now));
                if ($row) {
                    return $row->access_token;
                }
                // If still no token, fall through to request new one
            }
            
            // Set lock for 30 seconds (enough time for token refresh)
            set_transient($token_refresh_lock, 1, 30);
        }

        LHDN_Logger::log($force_new ? "Forcing new OAuth token (cron refresh)" : "Requesting OAuth token");

        $api_host = LHDN_Settings::get_api_host();
        $oauth_url = LHDN_Settings::get('oauth_url', '/connect/token');
        $client_id = LHDN_Settings::get('client_id', '');
        $client_secret1 = LHDN_Settings::get('client_secret1', '');
        $client_secret2 = LHDN_Settings::get('client_secret2', '');

        // Try with client_secret1 first
        $resp = wp_remote_post($api_host . "/" . $oauth_url, [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $client_id,
                'client_secret' => $client_secret1,
                'scope' => 'InvoicingAPI'
            ]
        ]);

        // Check if authorization error occurred and try fallback with client_secret2
        if (!is_wp_error($resp)) {
            $code = wp_remote_retrieve_response_code($resp);
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            
            // Check for HTTP 401 or OAuth error responses
            $is_auth_error = ($code === 401 || (empty($json['access_token']) && isset($json['error']) && in_array(strtolower($json['error']), ['invalid_client', 'unauthorized_client'])));
            
            if ($is_auth_error && !empty($client_secret2)) {
                LHDN_Logger::log("Authorization error with client_secret1, trying fallback with client_secret2");
                
                // Try with client_secret2 as fallback
                $resp = wp_remote_post($api_host . "/" . $oauth_url, [
                    'body' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $client_id,
                        'client_secret' => $client_secret2,
                        'scope' => 'InvoicingAPI'
                    ]
                ]);
                
                // Get response code and json from fallback attempt
                if (!is_wp_error($resp)) {
                    $code = wp_remote_retrieve_response_code($resp);
                    $json = json_decode(wp_remote_retrieve_body($resp), true);
                }
            }
        }

        if (is_wp_error($resp)) {
            LHDN_Logger::log($resp->get_error_message());
            // Release lock on error
            if ($force_new) {
                delete_transient($token_refresh_lock);
            }
            return false;
        }

        if (empty($json['access_token'])) {
            LHDN_Logger::log("Token request failed: " . json_encode($json));
            // Release lock on error
            if ($force_new) {
                delete_transient($token_refresh_lock);
            }
            return false;
        }

        $expires = wp_date('Y-m-d H:i:s', current_time('timestamp') + $json['expires_in'] - 60);

        $wpdb->insert($table, [
            'access_token' => $json['access_token'],
            'expires_at' => $expires,
            'created_at' => current_time('mysql')
        ]);

        $wpdb->query(
            "DELETE FROM {$table}
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM {$table}
                     ORDER BY id DESC
                     LIMIT 1
                 ) t
             )"
        );

        // Release the refresh lock if it was set
        if ($force_new) {
            delete_transient($token_refresh_lock);
        }

        return $json['access_token'];
    }

    /**
     * Validate TIN
     */
    public function validate_tin($tin_number, $id_type, $id_value) {
        // Force new token to ensure we have a valid token for validation
        $token = $this->get_token(true);
        if (!$token) {
            return [
                'status'  => 'error',
                'message' => 'Unable to obtain LHDN token'
            ];
        }

        $api_host = LHDN_Settings::get_api_host();
        $validate_url = LHDN_Settings::get('validate_tin_url', '/api/v1.0/taxpayer/validate/');
        
        // Remove leading/trailing slashes and ensure proper URL construction
        $validate_url = trim($validate_url, '/');
        $tin_encoded = rawurlencode($tin_number);
        $id_type_encoded = rawurlencode($id_type);
        $id_value_encoded = rawurlencode($id_value);
        
        $url = rtrim($api_host, '/') . '/' . $validate_url . '/' . $tin_encoded
             . "?idType=" . $id_type_encoded
             . "&idValue=" . $id_value_encoded;

        LHDN_Logger::log("Validating TIN | URL: {$url} | TIN: {$tin_number} | ID Type: {$id_type} | ID Value: {$id_value}");

        $resp = wp_remote_get($url, [
            'headers' => [
                "Authorization" => "Bearer {$token}",
                "Accept"        => "application/json"
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) {
            $error_message = $resp->get_error_message();
            LHDN_Logger::log("TIN validation error: {$error_message}");
            return [
                'status'  => 'error',
                'message' => $error_message
            ];
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        LHDN_Logger::log("TIN validation response | Code: {$code} | Body: {$body}");

        if ($code === 200) {
            LHDN_Logger::log("TIN validated successfully | TIN:" . $tin_number . " ID_TYPE:" . $id_type . " ID_Value:" . $id_value);
            return [
                'status'  => 'valid',
                'message' => 'TIN validated successfully'
            ];
        }

        if ($code === 404) {
            LHDN_Logger::log("TIN not found or mismatched | TIN:" . $tin_number . " ID_TYPE:" . $id_type . " ID_Value:" . $id_value);
            return [
                'status'  => 'invalid',
                'message' => 'TIN not found or mismatched'
            ];
        }

        if ($code === 401) {
            LHDN_Logger::log("TIN validation unauthorized (401) - reauthenticating and retrying");
            
            // Force new token and retry once
            $new_token = $this->get_token(true);
            if ($new_token) {
                LHDN_Logger::log("Retrying TIN validation with fresh token");
                $resp = wp_remote_get($url, [
                    'headers' => [
                        "Authorization" => "Bearer {$new_token}",
                        "Accept"        => "application/json"
                    ],
                    'timeout' => 30
                ]);

                if (!is_wp_error($resp)) {
                    $code = wp_remote_retrieve_response_code($resp);
                    $body = wp_remote_retrieve_body($resp);
                    LHDN_Logger::log("Retry TIN validation response | Code: {$code} | Body: {$body}");

                    if ($code === 200) {
                        LHDN_Logger::log("TIN validated successfully after reauth | TIN:" . $tin_number . " ID_TYPE:" . $id_type . " ID_Value:" . $id_value);
                        return [
                            'status'  => 'valid',
                            'message' => 'TIN validated successfully'
                        ];
                    }
                }
            }
            
            LHDN_Logger::log("TIN validation unauthorized (401) - token may be invalid");
            return [
                'status'  => 'error',
                'message' => 'Unauthorized - token may be invalid or expired'
            ];
        }

        if ($code === 403) {
            LHDN_Logger::log("TIN validation forbidden (403)");
            return [
                'status'  => 'error',
                'message' => 'Forbidden - insufficient permissions'
            ];
        }

        LHDN_Logger::log("TIN validation unexpected response | Code: {$code} | Body: {$body}");
        return [
            'status'  => 'error',
            'message' => 'Unexpected LHDN response (HTTP ' . $code . ')'
        ];
    }

    /**
     * Submit invoice
     */
    public function submit_invoice($payload) {
        $token = $this->get_token();
        if (!$token) {
            return false;
        }

        $api_host = LHDN_Settings::get_api_host();
        $submit_url = LHDN_Settings::get('submit_doc_url', '/api/v1.0/documentsubmissions/');

        $resp = wp_remote_post($api_host . "/" . $submit_url, [
            'headers' => [
                "Authorization" => "Bearer {$token}",
                "Content-Type"  => "application/json"
            ],
            'body' => json_encode($payload)
        ]);

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        LHDN_Logger::log("Submit HTTP {$code}");
        LHDN_Logger::log($body);

        // If we get a 401 (Unauthorized), reauthenticate and retry once
        if ($code === 401) {
            LHDN_Logger::log("Submit invoice unauthorized (401) - reauthenticating and retrying");
            
            $new_token = $this->get_token(true);
            if ($new_token) {
                LHDN_Logger::log("Retrying invoice submission with fresh token");
                $resp = wp_remote_post($api_host . "/" . $submit_url, [
                    'headers' => [
                        "Authorization" => "Bearer {$new_token}",
                        "Content-Type"  => "application/json"
                    ],
                    'body' => json_encode($payload)
                ]);

                $code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);

                LHDN_Logger::log("Retry Submit HTTP {$code}");
                LHDN_Logger::log($body);
            }
        }

        return [
            'code' => $code,
            'body' => $body,
            'data' => json_decode($body, true)
        ];
    }

    /**
     * Get document status
     */
    public function get_document_status($uuid) {
        $token = $this->get_token();
        if (!$token) {
            return false;
        }

        $api_host = LHDN_Settings::get_api_host();
        $get_doc_url = LHDN_Settings::get('get_doc_url', '/api/v1.0/documents/');

        $url = $api_host . "/" . $get_doc_url . $uuid . "/details";

        LHDN_Logger::log("Fetching status for UUID {$uuid}");

        $resp = wp_remote_get($url, [
            'headers' => [
                "Authorization" => "Bearer {$token}",
                "Accept"        => "application/json"
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($resp)) {
            LHDN_Logger::log($resp->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        LHDN_Logger::log("Status HTTP {$code}");
        LHDN_Logger::log($body);

        // If we get a 401 (Unauthorized), reauthenticate and retry once
        if ($code === 401) {
            LHDN_Logger::log("Get document status unauthorized (401) - reauthenticating and retrying");
            
            $new_token = $this->get_token(true);
            if ($new_token) {
                LHDN_Logger::log("Retrying get document status with fresh token");
                $resp = wp_remote_get($url, [
                    'headers' => [
                        "Authorization" => "Bearer {$new_token}",
                        "Accept"        => "application/json"
                    ],
                    'timeout' => 30
                ]);

                if (!is_wp_error($resp)) {
                    $code = wp_remote_retrieve_response_code($resp);
                    $body = wp_remote_retrieve_body($resp);

                    LHDN_Logger::log("Retry Status HTTP {$code}");
                    LHDN_Logger::log($body);
                } else {
                    LHDN_Logger::log($resp->get_error_message());
                    return false;
                }
            }
        }

        if ($code !== 200) {
            LHDN_Logger::log("Status sync failed");
            return false;
        }

        return [
            'code' => $code,
            'body' => $body,
            'data' => json_decode($body, true)
        ];
    }

    /**
     * Cancel document
     */
    public function cancel_document($uuid) {
        $token = $this->get_token();
        if (!$token) {
            return false;
        }

        $api_host = LHDN_Settings::get_api_host();
        $cancel_url = LHDN_Settings::get('cancel_doc_url', '/api/v1.0/documents/state/');

        $url = $api_host . "/" . $cancel_url . $uuid . "/state";

        $resp = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [
                "Authorization" => "Bearer {$token}",
                "Content-Type"  => "application/json"
            ],
            'body' => json_encode([
                "status"  => "cancelled",
                "reason" => "Cancelled by merchant"
            ])
        ]);

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        LHDN_Logger::log("Cancel HTTP {$code}");
        LHDN_Logger::log($body);

        // If we get a 401 (Unauthorized), reauthenticate and retry once
        if ($code === 401) {
            LHDN_Logger::log("Cancel document unauthorized (401) - reauthenticating and retrying");
            
            $new_token = $this->get_token(true);
            if ($new_token) {
                LHDN_Logger::log("Retrying cancel document with fresh token");
                $resp = wp_remote_request($url, [
                    'method'  => 'PUT',
                    'headers' => [
                        "Authorization" => "Bearer {$new_token}",
                        "Content-Type"  => "application/json"
                    ],
                    'body' => json_encode([
                        "status"  => "cancelled",
                        "reason" => "Cancelled by merchant"
                    ])
                ]);

                $code = wp_remote_retrieve_response_code($resp);
                $body = wp_remote_retrieve_body($resp);

                LHDN_Logger::log("Retry Cancel HTTP {$code}");
                LHDN_Logger::log($body);
            }
        }

        return [
            'code' => $code,
            'body' => $body,
            'success' => ($code >= 200 && $code < 300)
        ];
    }
}

