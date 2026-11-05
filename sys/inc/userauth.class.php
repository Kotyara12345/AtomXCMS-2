<?php


/**
 *
 */
#[\AllowDynamicProperties]
class UserAuth
{



    /**
     * @return void
     */
    public function setTimeVisit()
    {
        $userId = !empty($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        if ($userId < 1) return;
        $last = isset($_SESSION['last_visit_write']) ? $_SESSION['last_visit_write'] : array();
        if (isset($last['user'], $last['time']) && (int)$last['user'] === $userId && (int)$last['time'] > time() - 60) return;
        $Register = Register::getInstance();
        $FpsDB = $Register['DB'];
        
        $query = "UPDATE `" . $FpsDB->getFullTableName('users') . "`
                SET last_visit=NOW()
                WHERE id=" . $userId;
        $FpsDB->query( $query );
        $_SESSION['last_visit_write'] = array('user' => $userId, 'time' => time());
    }



    /**
     * Restore the session from the "remember me" cookie.
     *
     * The legacy autologin cookies (userid + the raw password hash) are no
     * longer trusted: whoever obtains the hash could sign in without
     * knowing the password. They are removed if still present.
     *
     * @return bool|void
     */
    public function autoLogin()
    {
        $Register = Register::getInstance();

        if (isset($_COOKIE['userid']) || isset($_COOKIE['password']) || isset($_COOKIE['autologin'])) {
            $path = '/';
            setcookie('autologin', '', time() - 1, $path);
            setcookie('userid', '', time() - 1, $path);
            setcookie('password', '', time() - 1, $path);
        }

        $user = atm_user_remember_login();
        if (empty($user) || empty($user['id'])) {
            return false;
        }

        if (!empty($user['activation'])) {
            atm_user_remember_forget();
            return false;
        }

        // Если пользователь заблокирован
        if (!empty($user['locked'])) {
            atm_user_remember_forget();
            redirect('/users/baned/');
        }

        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['user'] = $user;

        // Функция getNewThemes() помещает в массив $_SESSION['newThemes'] ID тем,
        // в которых были новые сообщения со времени последнего посещения пользователя
        $this->getNewThemes();

        return true;
    }



    public function getNewThemes()
    {
        $Register = Register::getInstance();
        $FpsDB = $Register['DB'];
        
	    $query = "SELECT id, UNIX_TIMESTAMP(last_post) AS unix_last_post
			FROM `" . $FpsDB->getFullTableName('themes') . "` 
			WHERE last_post > FROM_UNIXTIME(" . (int)(isset($_SESSION['user']['unix_last_visit']) ? $_SESSION['user']['unix_last_visit'] : 0) . ")";

	    $res = $FpsDB->query($query);

	    if ($res) {
            foreach ($res as $key => $row) {
                $_SESSION['newThemes'][$row['id']] = $row['unix_last_post'];
            }
		}
	}



    public static function countNewMessages() {
        global $Register;
        $FpsDB = $Register['DB'];
        
        $res = $FpsDB->query("SELECT COUNT(*) as cnt
                FROM `" . $FpsDB->getFullTableName('messages') . "`
                WHERE to_user=".(int)$_SESSION['user']['id']."
                AND viewed=0 AND id_rmv<>".(int)$_SESSION['user']['id']);
        if ( $res ) {
            return $res[0]['cnt'];
        } else {
            return 0;
        }
    }
}
