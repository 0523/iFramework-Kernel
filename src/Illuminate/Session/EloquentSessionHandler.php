<?php
/**
 * 新增 eloquent session 驱动
 */

namespace Illuminate\Session;

use SessionHandlerInterface;

use Hm;

class EloquentSessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    /**
     * The existence state of the session.
     *
     * @var bool
     */
    protected $exists;

    /**
     * Create a new database session handler instance.
     *
     * @return mixed
     */
    public function __construct()
    {
        if (env('APP_ENV') == 'development') {
            new Hm\Sys\Brow();
            new Hm\Sys\Sess();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($savePath, $sessionName)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($sessionId)
    {
        $sessionPk = uuidtouint($sessionId);

        if ($sessionPk) {
            $session = Hm\Sys\Sess::find($sessionPk);
            if ($session) {
                $this->exists = true;

                /**
                 * 其他数据表的内容
                 */
                $sessionOther = $session->toArray();
                unset($sessionOther['data']);

                return array_merge(json_decode($session->data, true), $sessionOther);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $data)
    {
        $sessionPk = uuidtouint($sessionId);

        if ($sessionPk) {
            $session = [
                'uint'          => $sessionPk,
                'data'          => json_encode($data),
                'last_activity' => time(),
            ];

            if (! empty($this->login_time)) {
                $session['activity_long'] = time() - strtotime($this->login_time);
            }

            if (! $this->exists) {
                $this->read($sessionId);
            }

            if ($this->exists) {
                Hm\Sys\Sess::where('uint', $sessionPk)->update($session);
            } else {
                if (browser_uuid()) {
                    $Brow = Hm\Sys\Brow::firstOrCreate(['uint' => uuidtouint(browser_uuid())]);
                    $session['sys_brow_uint'] = $Brow->uint;
                    Hm\Sys\Sess::create($session);
                }
            }

            $this->exists = true;
        }

        return true;
    }

    /**
     * updateLoginInfo
     *
     * 更新账号信息, 账号登录成功之后触发
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param string $sessionId
     * @param object $Acc
     *
     * @return mixed
     */
    public function updateLoginInfo($request, $sessionId, $Acc)
    {
        if ($Acc) {
            $sessionPk = uuidtouint($sessionId);

            // 账号登录次数 +1
            $Acc->login_times += 1;

            $session = [
                'uint'          => $sessionPk,
                'last_activity' => time(),
                'sys_acc_uint'  => $Acc->getAuthIdentifier(),
                'login_times'   => $Acc->login_times,
                'login_time'    => current_timestring(),
                'activity_long' => 1,
            ];

            $session['user_agent'] = strval($request->header('User-Agent'));
            $session['ip_address'] = strval($request->ip());
            $session['ip_local'] = Hm\Api\QqWry::getLocal($request->ip());

            $Acc->last_login_time = current_timestring();
            $Acc->last_login_ip = $session['ip_address'];

            if ($session['ip_local'] != $Acc->last_login_local) {
                $session['local_change'] = true;
                $Acc->last_login_local = $session['ip_local'];
            } else {
                $session['local_change'] = false;
            }

            $Acc->save();

            if (! $this->exists) {
                /**
                 * 实测 是不存在的!!!
                 */
                if (browser_uuid()) {
                    $Brow = Hm\Sys\Brow::firstOrCreate(['uint' => uuidtouint(browser_uuid())]);
                    $session['sys_brow_uint'] = $Brow->uint;
                    Hm\Sys\Sess::create($session);
                    $this->exists = true;
                }
            } else {
                Hm\Sys\Sess::where('uint', $sessionPk)->update($session);
            }

            // 浏览器的 系统 引擎 核心
            do {
                (isset($Brow) and is_object($Brow)) or $Brow = Hm\Sys\Brow::firstOrCreate(['uint' => uuidtouint(browser_uuid())]);

                if ($Brow) {
                    $update = 0;

                    if (! isset($Brow->os) or $Brow->os === null) {
                        $Brow->os = $request->input('os');
                        $update++;
                    }

                    if (! isset($Brow->engine) or $Brow->engine === null) {
                        $Brow->engine = $request->input('engine');
                        $update++;
                    }

                    if (! isset($Brow->browser) or $Brow->browser === null) {
                        $Brow->browser = $request->input('browser');
                        $update++;
                    }

                    if ($update) {
                        $Brow->save();
                    }
                }
            } while (0);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId)
    {
        $sessionPk = uuidtouint($sessionId);

        if ($sessionPk) {
            /*
             * sys_acc_uint 为空 ,表示是未登录的会话数据
             * 可以销毁
             */
            if (! Hm\Sys\Sess::where('uint', $sessionPk)->whereNull('sys_acc_uint')->delete()) {
                /*
                 * user_id 非空
                 * 可以清空 data
                 */
                Hm\Sys\Sess::where('uint', $sessionPk)->update([
                    'data' => null,
                ]);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($lifetime)
    {
        /**
         * sys_acc_uint 为空 ,表示是未登录的会话数据
         * 可以清理
         */
        Hm\Sys\Sess::where('last_activity', '<=', time() - $lifetime)->whereNull('sys_acc_uint')->delete();

        /**
         * user_id 非空
         * 可以清空 data
         */
        Hm\Sys\Sess::where('last_activity', '<=', time() - $lifetime)->update([
            'data' => null,
        ]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setExists($value)
    {
        $this->exists = $value;

        return $this;
    }
}
