<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

require_once('phpass-0.1/PasswordHash.php');

class Sauth
{
  public $error = array();
  public $confirmation = array();

  function __construct()
  {
    $this->ci =& get_instance();

    $this->ci->load->database();
    $this->ci->load->model('user_dal');

    // try to autologin
    $this->autologin();
  }

  /**
   * Log the user in
   *
   * @param	string
   * @param	string
   * @return	bool
   */
  function login($username, $password)
  {

    if (!is_null($user = $this->ci->user_dal->get_user_by_username($username))) {

      $hasher = new PasswordHash(8, FALSE);
      if ($hasher->CheckPassword($password, $user->password)) {
        if ($user->banned == 1) {
          $this->error = array('banned' => $user->ban_reason);
        } else {
          $data = array(
            'user_id' => $user->id,
            'username' => $user->username,
            'status' => ($user->activated == 1) ? 1 : 0,
            'threads_shown' => $user->threads_shown,
            'comments_shown' => $user->comments_shown,
            'view_html' => $user->view_html,
            'new_post_notification' => $user->new_post_notification,
            'random_titles' => $user->random_titles,
            'emoticon' => $user->emoticon
          );
          $this->ci->session->set_userdata($data);
          $this->create_autologin($user->id);

          $this->clear_login_attempts($username);

          $ip = $this->ci->config->item('login_record_ip', 'auth');
          $time = $this->ci->config->item('login_record_time', 'auth');
          $this->ci->user_dal->update_login_info($user->id, $ip, $time);

          return TRUE;
        }

      } else {
        $this->increase_login_attempt($username);
      }
    }

    $this->error = array('login' => "Login incorrect");

    return FALSE;
  }

  /**
   * Logout user from the site
   *
   * @return	void
   */
  function logout()
  {
    $data = array('user_id' => '', 'username' => '', 'status' => '');

    $this->delete_autologin();
    $this->ci->session->set_userdata($data);
    $this->ci->session->sess_destroy();
  }

  /**
   * Insert a new yh invite record into the database
   *
   * @return	void
   */
  function yh_invite($username, $invite_id)
  {
    if ($this->ci->user_dal->is_yh_username_available($username)) {
      $this->ci->user_dal->create_yh_invite($username, $invite_id);
      $this->confirmation = array(
        'invite' => "Invitation sent. Please let us know if you don't get one."
      );
      return TRUE;
    } else {
      $this->error = array(
        'invite' => 'An invitation has already been sent to that username'
      );
    }

    return FALSE;
  }

  /**
   * Create new user on the site
   *
   * @param	string
   * @param	string
   * @param	string
   * @param	string
   * @return	bool
   */
  function create_user($username, $email, $password)
  {
    if ((strlen($username) > 0) AND
        !$this->ci->user_dal->is_username_available($username)) {
      $this->error = 'That username is already in use';
    } elseif (!$this->ci->user_dal->is_email_available($email)) {
      $this->error = 'That email address is already in use';
    } else {

      $hasher = new PasswordHash(8, FALSE);
      $user = array('username'	=> $username,
                    'password'	=> $hasher->HashPassword($password),
                    'email'	=> $email,
                    'last_ip'	=> $this->ci->input->ip_address());

      // insert the user into the database
      $user_id = $this->ci->user_dal->create_user($user);

      return TRUE;
    }
    return FALSE;
  }

  function reset_password($data)
  {
    $hasher = new PasswordHash(8, FALSE);

    $this->ci->user_dal->reset_password($data['id'],
                                        $hasher->HashPassword($data['password']));
  }

  /**
   * Check if user logged in. Also test if user is activated or not.
   *
   * @param	bool
   * @return	bool
   */
  function is_logged_in($activated = TRUE)
  {
    return $this->ci->session->userdata('status') === ($activated ? 1 : 0);
  }

  /**
   * Get user_id
   *
   * @return	string
   */
  function get_user_id()
  {
    return $this->ci->session->userdata('user_id');
  }

  /**
   * Get error message.
   * Can be invoked after any failed operation such as login or register.
   *
   * @return	string
   */
  function get_error_message()
  {
    return $this->error;
  }

  /**
   * Get confirmation message.
   *
   * @return	string
   */
  function get_confirmation_message()
  {
    return $this->confirmation;
  }

  /**
   * Save data for user's autologin
   *
   * @param	int
   * @return	bool
   */
  private function create_autologin($user_id)
  {
    $this->ci->load->helper('cookie');
    $key = substr(md5(uniqid(rand().get_cookie('session'))), 0, 16);

    $this->ci->load->model('auth/user_autologin');
    $this->ci->user_autologin->purge($user_id);

    if ($this->ci->user_autologin->set($user_id, md5($key))) {
      set_cookie(array(
        'name' => 'autologin',
        'value'  => serialize(array('user_id' => $user_id, 'key' => $key)),
        'expire' => 5356800,
      ));
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Clear user's autologin data
   *
   * @return	void
   */
  private function delete_autologin()
  {
    $this->ci->load->helper('cookie');
    if ($cookie = get_cookie('autologin', TRUE)) {

      $data = unserialize($cookie);

      $this->ci->load->model('auth/user_autologin');
      $this->ci->user_autologin->delete($data['user_id'], md5($data['key']));

      delete_cookie('autologin');
    }
  }

  /**
   * Login user automatically if he/she provides correct autologin verification
   *
   * @return	bool
   */
  private function autologin()
  {
    // not logged in (as any user)
    if (!$this->is_logged_in() AND !$this->is_logged_in(FALSE)) {
      $this->ci->load->helper('cookie');
      if ($cookie = get_cookie('autologin', TRUE)) {

        $data = unserialize($cookie);

        if (isset($data['key']) AND isset($data['user_id'])) {

          $this->ci->load->model('auth/user_autologin');
          $user = $this->ci->user_autologin->get($data['user_id'],md5($data['key']));
          if (!is_null($user)) {

            // Login user
            $this->ci->session->set_userdata(array(
              'user_id' => $user->id,
              'username' => $user->username,
              'status'	=> 1,
            ));

            // Renew users cookie to prevent it from expiring
            set_cookie(array(
              'name' => 'autologin',
              'value' => $cookie,
              'expire' => 5356800,
            ));

            $ip = $this->ci->config->item('login_record_ip', 'auth');
            $time = $this->ci->config->item('login_record_time', 'auth');
            $this->ci->user_dal->update_login_info($user->id, $ip, $time);
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Check if login attempts exceeded max login attempts (specified in config)
   *
   * @param	string
   * @return	bool
   */
  function is_max_login_attempts_exceeded($login)
  {
    $this->ci->load->model('auth/login_attempts');
    return $this->ci->login_attempts
      ->get_attempts_num($this->ci->input->ip_address(), $login) >= 5;
  }

  /**
   * Increase number of attempts for given IP-address and login
   * (if attempts to login is being counted)
   *
   * @param	string
   * @return	void
   */
  private function increase_login_attempt($login)
  {
    if (!$this->is_max_login_attempts_exceeded($login)) {
      $this->ci->load->model('auth/login_attempts');
      $this->ci->login_attempts
        ->increase_attempt($this->ci->input->ip_address(), $login);
    }
  }

  /**
   * Clear all attempt records for given IP-address and login
   * (if attempts to login is being counted)
   *
   * @param	string
   * @return	void
   */
  private function clear_login_attempts($login)
  {
    $this->ci->load->model('auth/login_attempts');
    $this->ci->login_attempts->clear_attempts($this->ci->input->ip_address(),
                                              $login, 86400);
  }
}

/* End of file sauth.php */
/* Location: ./application/libraries/auth.php */