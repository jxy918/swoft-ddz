<?php
/**
 * 斗地主Ai机器人
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

class Ai {
    /**
     * 服务器ip
     */
    const IP = '192.168.7.197';
    /**
     * 服务器端口
     */
    const PORT = 18308;

    /**
     * 客户端参数设置
     * @var array
     */
    private $_setconfig = array(
        'websocket_mask' => true,
    );

    /**
     * 客户端头部设置
     * @var array
     */
    private $_header = array(
        'UserAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36',
    );

    /**
     * 用户登陆账号
     * @var string
     */
    public $account = '';

    /**
     * 心跳定时器
     * @var int
     */
    public $heart_timer = 0;

    /**
     * 心跳定时器间隔时间(毫秒)
     * @var int
     */
    public $heart_interval = 60000;

    /**
     * 断线重连定时器
     * @var int
     */
    public $reback_timer = 0;

    /**
     * 断线重连次数
     * @var int
     */
    public $reback_times = 10;

    /**
     * 断线重连计数器
     * @var int
     */
    public $reback_count = 0;

    /**
     * 断线重连间隔时间(毫秒)
     * @var int
     */
    public $reback_interval = 2000;

    /**
     * 椅子id
     * @var array
     */
    public $chair_id = 0;

    /**
     * 手牌数据
     * @var array
     */
    public $hand_card = array();

    /**
     * 打牌步骤
     * @var int
     */
    public $step = 1;

    /**
     * 当前出牌的椅子id
     * @var int
     */
    public $current_chair_id = 0;

    /**
     * 当前牌型
     * @var int
     */
    public $current_card_type = 0;

    /**
     * 手牌对象
     * @var null
     */
    public $ddz = null;

    /**
     * 路由规则
     * @var array
     */
    public $route = array(
        //系统请求响应
        App\Game\Conf\MainCmd::CMD_SYS => array(
            \App\Game\Conf\SubCmd::LOGIN_FAIL_RESP =>'loginFailResp',
            \App\Game\Conf\SubCmd::LOGIN_SUCCESS_RESP =>'loginSucessResp',
            \App\Game\Conf\SubCmd::HEART_ASK_RESP =>'heartAskResp',
            \App\Game\Conf\SubCmd::ENTER_ROOM_FAIL_RESP =>'enterRoomFailResp',
            \App\Game\Conf\SubCmd::ENTER_ROOM_SUCC_RESP =>'enterRoomSuccResp',
        ),
        //游戏请求响应
        App\Game\Conf\MainCmd::CMD_GAME => array(
            \App\Game\Conf\SubCmd::SUB_GAME_START_RESP =>'gameStartResp',
            \App\Game\Conf\SubCmd::SUB_USER_INFO_RESP =>'userInfoResp',
            \App\Game\Conf\SubCmd::CHAT_MSG_RESP =>'chatMsgResp',
            \App\Game\Conf\SubCmd::SUB_GAME_CALL_TIPS_RESP =>'gameCallTipsResp',
            \App\Game\Conf\SubCmd::SUB_GAME_CALL_RESP =>'callGameResp',
            \App\Game\Conf\SubCmd::SUB_GAME_CATCH_BASECARD_RESP =>'catchGameCardResp',
            \App\Game\Conf\SubCmd::SUB_GAME_OUT_CARD =>'gameOutCard',
        ),
    );

    /**
     * 构造函数
     * Ai constructor.
     * @param string $account
     */
    public function __construct($account = ''){
        if($account) {
            $this->account = $account;
        }
    }

    /**
     * 运行服务器
     */
    public function run(){
        if($this->account) {
            $this->createConnection();
        } else {
            \App\Game\Core\Log::show("账号错误!");
        }
    }

    /**
     * 创建链接
     */
    protected function createConnection() {
        go(function () {
            $cli = new \Swoole\Coroutine\Http\Client(self::IP, self::PORT);
            $cli->set($this->_setconfig);
            $cli->setHeaders($this->_header);
            $cli->setMethod("GET");
            $self = $this;
            $data = array('account' => $this->account);
            $token = json_encode($data);
            $ret = $cli->upgrade('/game?token=' . $token);
            if($ret && $cli->connected) {
                //清除断线重连定时器, 断线重连次数重置为0
                Swoole\Timer::clear($this->reback_timer);
                $this->reback_count = 0;
                $self->chatMsgReq($cli);
                $self->heartAskReq($cli);
                while (true) {
                    $ret = $self::onMessage($cli, $cli->recv());
                    if(!$ret) {
                        break;
                    }
                }

            }
        });
    }

    /**
     * websocket 消息处理
     * @param $cli
     * @param $frame
     * @return bool
     */
    public function onMessage($cli, $frame) {
        \App\Game\Core\Log::show('原数据:'.$frame->data);
        $ret = false;
        if($cli->connected && $frame) {
            $total_data = $frame->data;
            $total_len = strlen($total_data);
            if ($total_len < 4) {
                //清除定时器
                Swoole\Timer::clear($this->timer);
                //断开链接
                $cli->close();
                \App\Game\Core\Log::show('数据包格式有误!');
            } else {
                //需要进行粘包处理
                $off = 0;   //结束时指针
                while ($total_len > $off) {
                    $header = substr($total_data, $off, 4);
                    $arr = unpack("Nlen", $header);
                    $len = isset($arr['len']) ? $arr['len'] : 0;
                    if ($len) {
                        $data = substr($total_data, $off, $off + $len + 4);
                        $body = \App\Game\Core\Packet::packDecode($data);
                        $this->dispatch($cli, $body);
                        $off += $len + 4;
                    } else {
                        break;
                    }
                }
            }
            $ret = true;
        } else {
            //清除定时器
            Swoole\Timer::clear($this->heart_timer);
            Swoole\Timer::clear($this->reback_timer);
            //链接断开, 可以尝试断线重连逻辑
            $cli->close();
            \App\Game\Core\Log::show('链接断开: 清除定时器, 断开链接!');
            //断线重连逻辑
            $this->rebackConnection();
        }
        return $ret;
    }

    /**
     * 断线重连
     */
    protected function rebackConnection() {
        \App\Game\Core\Log::show('断线重连开始');
        //定时器发送数据，发送心跳数据
        $this->reback_timer = Swoole\Timer::tick($this->reback_interval, function () {
            if($this->reback_count < $this->reback_times) {
                $this->reback_count++;
                $this->createConnection();
                \App\Game\Core\Log::show('断线重连' . $this->reback_count . '次');
            } else {
                Swoole\Timer::clear($this->reback_timer);
                Swoole\Timer::clear($this->heart_timer);
            }
        });
    }

    /**
     * 转发到不同的逻辑处理
     * @param $cli
     * @param $cmd
     * @param $scmd
     * @param $data
     */
    protected function dispatch($cli, $data) {
        $cmd = isset($data['cmd']) ? intval($data['cmd']) : 0;
        $scmd = isset($data['scmd']) ? intval($data['scmd']) : 0;
        $len = isset($data['len']) ? intval($data['len']) : 0;
        $method = isset($this->route[$cmd][$scmd]) ? $this->route[$cmd][$scmd] : '';
        if($method) {
            if($method != 'heartAskResp') {
                \App\Game\Core\Log::show('----------------------------------------------------------------------------------------------');
                \App\Game\Core\Log::show('cmd = ' . $cmd . ' scmd =' . $scmd . ' len=' . $len . ' method=' . $method);
            }
            $this->$method($cli, $data['data']['data']);
        } else {
            \App\Game\Core\Log::show('cmd = '.$cmd . ' scmd =' .$scmd .' ,method is not exists');
        }
    }

    /**
     * 聊天请求
     * @param $cli
     */
    protected function chatMsgReq($cli) {
        if($cli->connected) {
            $msg = array('data' => 'this is a test msg');
            $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::CHAT_MSG_REQ);
            $cli->push($data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * 触发心跳
     * @param $cli
     */
    protected function heartAskReq($cli) {
        //定时器发送数据，发送心跳数据
        $this->heart_timer = Swoole\Timer::tick($this->heart_interval, function () use ($cli) {
            list($t1, $t2) = explode(' ', microtime());
            $time =  (float)sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
            $msg = array('time' => $time);
            $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_SYS, \App\Game\Conf\SubCmd::HEART_ASK_REQ);
            $ret = $cli->push($data, WEBSOCKET_OPCODE_BINARY);
            if(!$ret) {
                $this->loginFail($cli);
            }
        });
    }

    /**
     * 触发游戏开始
     * @param $cli
     */
    protected function gameStartReq($cli) {
        if($cli->connected) {
            $msg = array('data' => 'this is a test msg');
            $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::SUB_GAME_START_REQ);
            $cli->push($data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * 出牌请求
     * @param $cli
     * @param bool $is_first_round 是否为首轮, 首轮必须出牌
     */
    protected function outCardReq($cli, $is_first_round = false) {
        if($cli->connected) {
            //判断手牌是否能要的起, 如果要的起, 就打牌, 要不起就过牌
//            $ddz = $this->getDdzObj();
//            $ddz->isPlayCard($this->hand_card, $cur_card);

            \App\Game\Core\Log::show("开始出牌:");
            if($is_first_round) {
                $status = 1;
            } else {
                $status = array_rand(array(1, 2));   //出牌状态随机
            }
            if($status == 1) {
                //跟牌, 从手牌中, 一张一张弹出牌来
                $cards = array_shift($this->hand_card);
                $card_type = 1;
                $msg = array(
                    'status' => $status, //打牌还是过牌, 1跟牌, 2是过牌
                    'chair_id' => $this->chair_id,
                    'card_type' => $card_type,
                    'card' => array($cards),
                );
            } else {
                //过牌
                $msg = array(
                    'status' => $status, //打牌还是过牌, 1跟牌, 2是过牌
                    'chair_id' => $this->chair_id,
                    'card_type' => 0,
                    'card' => array(),
                );
            }
            $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::SUB_GAME_OUT_CARD_REQ);
            $cli->push($data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * 响应登录失败
     * @param $cli
     */
    protected function loginFailResp($cli, $data) {
        $cli->close();
        Swoole\Timer::clear($this->heart_timer);
        Swoole\Timer::clear($this->reback_timer);
        \App\Game\Core\Log::show("关闭客户端, 清除定时器");
    }

    /**
     * 响应登录成功
     * @param $cli
     */
    protected function loginSucessResp($cli, $data) {
        //登录成功, 开始游戏逻辑
        $this->gameStartReq($cli);
        \App\Game\Core\Log::show("登录成功, 并开始游戏请求");
    }

    /**
     * 响应处理心跳
     * @param $cli
     */
    protected function heartAskResp($cli, $data) {
        //定时器发送数据，发送心跳数据
        \App\Game\Core\Log::show('心跳(毫秒):' .$data['time']);
    }

    /**
     * 响应处理聊天
     * @param $cli
     */
    protected function chatMsgResp($cli, $data) {
        //定时器发送数据，发送心跳数据
        \App\Game\Core\Log::show('聊天内容:'.json_encode($data));
    }

    /**
     * 触发游戏开始
     * @param $cli
     */
    protected function gameStartResp($cli, $data) {
        \App\Game\Core\Log::show('游戏场景数据:'.json_encode($data));
    }

    /**
     * 解说用户信息协议
     * @param $cli
     */
    protected function userInfoResp($cli, $data) {
        \App\Game\Core\Log::show('用户数据数据:'.json_encode($data));
    }

    /**
     * 进入房间后， 开始抢地主
     * @param $cli
     */
    protected function enterRoomSuccResp($cli, $data) {
        \App\Game\Core\Log::show('进入房间成功数据:'.json_encode($data));
        //保存用户信息和手牌信息
        $this->chair_id = $data['data']['chair_id'];
        $this->hand_card = $data['data']['card'];
        //根据自己的牌是否可以发送是否叫地主,  0,不叫, 1,叫地主, 2, 抢地主
        $obj = new \App\Game\Core\DdzPoker();
        $ret = $obj->isGoodCard($data['data']['card']);
        if($ret) {
            $status = 1;
        } else {
            $status = 0;
        }
        //发送是否叫地主操作
        $msg = array('type' => $status);
        $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::SUB_GAME_CALL_REQ);
        $cli->push($data, WEBSOCKET_OPCODE_BINARY);
    }

    /**
     * 叫完地主单条提示
     * @param $cli
     */
    protected function callGameResp($cli, $data) {
        \App\Game\Core\Log::show('叫地主提示:'.json_encode($data));
        //如果地主一定产生, 请重新链接回来继续往下打牌逻辑, 如果发现是自己出牌, 请继续出牌
        if(isset($data['master']) && isset($data['last_chair_id'])) {
            $last_chair_id = $data['last_chair_id'];
            $next_chair_id = $last_chair_id + 1;
            $next_chair_id = $next_chair_id > 3 ? $next_chair_id - 3 : $next_chair_id;
            //出牌请求
            if($next_chair_id == $this->chair_id) {
                $this->outCardReq($cli);
            }
        }
    }

    /**
     * 叫完地主广播提示
     * @param $cli
     */
    protected function gameCallTipsResp($cli, $data) {
        \App\Game\Core\Log::show('叫地主提示:'.json_encode($data));
    }

    /**
     * 触发游戏开始
     * @param $cli
     */
    protected function catchGameCardResp($cli, $data) {
        \App\Game\Core\Log::show('摸牌响应:'.json_encode($data));
        if(isset($data['chair_id']) && $data['chair_id'] == $this->chair_id) {
            //合并手牌
            $hand_card = json_decode($data['hand_card'], true);
            $this->hand_card = $this->getDdzObj()->_sortCardByGrade(array_merge($this->hand_card, $hand_card));
            \App\Game\Core\Log::show('地主['.$this->account.']出牌:'.json_encode($this->hand_card));
            //出牌请求
            $this->outCardReq($cli, true);
        }
    }

    /**
     * 出牌提示
     * @param $cli
     */
    protected function gameOutCard($cli, $data) {
        \App\Game\Core\Log::show('出牌提示:'.json_encode($data));
        $show_type = array(
            1=>'跟牌',
            2=>'过牌'
        );
        $tips = $data['account'].$show_type[$data['show_type']] . ', 本次出牌椅子id是:'. $data['chair_id'] .'下一个出牌椅子id是:' . $data['next_chair_id'];
        \App\Game\Core\Log::show('出牌提示:'.$tips);

        if($this->chair_id == $data['next_chair_id']) {
            //处理下一个出牌逻辑
            $this->outCardReq($cli, $data['is_first_round']);
        }
    }

    /**
     * 说有没处理的方法， 输出
     * @param $name
     * @param $arguments
     */
    public function __call($name, $arguments)
    {
        \App\Game\Core\Log::show($name.':'.json_encode($arguments[1]));
    }

    /**
     * 获取手牌对象
     */
    public function getDdzObj() {
        if($this->ddz === null) {
            $this->ddz = new \App\Game\Core\DdzPoker();
        }
        return $this->ddz;
    }
}

$ai = new Ai($argv[1]);
$ai->run();