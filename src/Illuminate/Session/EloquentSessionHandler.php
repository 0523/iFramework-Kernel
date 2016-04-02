<?php
/**
 * 新增 eloquent session 驱动
 */

namespace Illuminate\Session;

use SessionHandlerInterface;

use Gm;
use Hm;

class EloquentSessionHandler implements SessionHandlerInterface, ExistenceAwareInterface
{
    /**
     * The name of the session model.
     *
     * @var string
     */
    protected $model;

    /**
     * The existence state of the session.
     *
     * @var bool
     */
    protected $exists;

    /**
     * 登入时间
     *
     * @var bool
     */
    protected $login_time;

    /**
     * Create a new database session handler instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return mixed
     */
    public function __construct($model)
    {
        if (env('APP_ENV') == 'development') {
            new $model();
        }

        $this->model = $model;
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
            $model = $this->model;
            $session = $model::find($sessionPk);
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

            $model = $this->model;

            if ($this->exists) {
                $model::where('uint', $sessionPk)->update($session);
            } else {
                if (current_browser_uuid()) {
                    $brow = Hm\Api\Brow::firstOrCreate(['uint' => uuidtouint(current_browser_uuid())]);
                    $session['api_brow_uint'] = $brow->uint;
                    $model::create($session);
                }
            }

            $this->exists = true;
        }

        return true;
    }

    /**
     * update_login_info
     *
     * 更新登录信息, 用户登录成功之后触发
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param string $sessionId
     * @param object $user
     *
     * @return mixed
     */
    public function update_login_info($request, $sessionId, $user)
    {
        if ($user) {
            $model = $this->model;
            $sessionPk = uuidtouint($sessionId);

            // 用户登录次数 +1
            $user->login_times += 1;
            $user->save();

            $session = [
                'uint'          => $sessionPk,
                'last_activity' => time(),
                'user_id'       => $user->getAuthIdentifier(),
                'login_times'   => $user->login_times,
                'login_time'    => current_timestring(),
                'activity_long' => 1,
            ];

            $session['user_agent'] = strval($request->header('User-Agent'));
            if ($request->ip()) {
                $session['ip_address'] = strval($request->ip());
                $session['ip_local'] = Gm\Api\QqWry::get_local($request->ip());
            }

            if (! $this->exists) {
                /**
                 * 实测 是不存在的!!!
                 */
                if (current_browser_uuid()) {
                    $brow = Hm\Api\Brow::firstOrCreate(['uint' => uuidtouint(current_browser_uuid())]);
                    $session['apis_browsers_uint'] = $brow->uint;
                    $model::create($session);
                    $this->exists = true;
                }
            } else {
                $model::where('uint', $sessionPk)->update($session);
            }

            // 浏览器的 系统 引擎 核心
            do {
                (isset($brow) and is_object($brow)) or $brow = Hm\Api\Brow::firstOrCreate(['uint' => uuidtouint(current_browser_uuid())]);

                if ($brow) {
                    $update = 0;

                    if (! isset($brow->os) or $brow->os === null) {
                        $brow->os = $request->input('os');
                        $update++;
                    }

                    if (! isset($brow->engine) or $brow->engine === null) {
                        $brow->engine = $request->input('engine');
                        $update++;
                    }

                    if (! isset($brow->browser) or $brow->browser === null) {
                        $brow->browser = $request->input('browser');
                        $update++;
                    }

                    if ($update) {
                        $brow->save();
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
            $model = $this->model;
            /*
             * user_id 为空 ,表示是未登录的会话数据
             * 可以销毁
             */
            $model::where('uint', $sessionPk)->whereNull('user_id')->delete();

            /*
             * user_id 非空
             * 可以清空 data
             */
            $model::where('uint', $sessionPk)->update([
                'data' => null,
            ]);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($lifetime)
    {
        /**
         * user_id 为空 ,表示是未登录的会话数据
         * 可以清理
         */
        $model = $this->model;
        $model::where('last_activity', '<=', time() - $lifetime)->whereNull('user_id')->delete();

        /**
         * user_id 非空
         * 可以清空 data
         */
        $model::where('last_activity', '<=', time() - $lifetime)->update([
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
