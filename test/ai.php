<?php
/**
 * 斗地主Ai机器人
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';


class Ai {
    /**
     * 服务器ip
     */
    const IP = '127.0.0.1';
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
     * 房间信息
     * @var array
     */
    public $my_room_info = array();

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
            \App\Game\Conf\SubCmd::LOGIN_FAIL_RESP =>'loginFailResp',    //登录失败响应
            \App\Game\Conf\SubCmd::LOGIN_SUCCESS_RESP =>'loginSucessResp',  //登录成功响应
            \App\Game\Conf\SubCmd::HEART_ASK_RESP =>'heartAskResp',         //心跳响应
            \App\Game\Conf\SubCmd::ENTER_ROOM_FAIL_RESP =>'enterRoomFailResp',  //进入房间失败响应
            \App\Game\Conf\SubCmd::ENTER_ROOM_SUCC_RESP =>'enterRoomSuccResp',  //进入房间成功响应
        ),
        //游戏请求响应
        App\Game\Conf\MainCmd::CMD_GAME => array(
            \App\Game\Conf\SubCmd::SUB_GAME_START_RESP =>'gameStartResp',   //游戏开始响应
            \App\Game\Conf\SubCmd::SUB_USER_INFO_RESP =>'userInfoResp',     //用户信息响应
            \App\Game\Conf\SubCmd::CHAT_MSG_RESP =>'chatMsgResp',           //聊天,消息响应
            \App\Game\Conf\SubCmd::SUB_GAME_CALL_TIPS_RESP =>'gameCallTipsResp',    //叫地主广播响应
            \App\Game\Conf\SubCmd::SUB_GAME_CALL_RESP =>'gameCallResp',         //叫地主响应
            \App\Game\Conf\SubCmd::SUB_GAME_CATCH_BASECARD_RESP =>'catchGameCardResp',  //摸牌广播响应
            \App\Game\Conf\SubCmd::SUB_GAME_OUT_CARD =>'gameOutCard',   //出牌广播
            \App\Game\Conf\SubCmd::SUB_GAME_OUT_CARD_RESP =>'gameOutCardResp',  //出牌响应
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
//                $self->chatMsgReq($cli); //测试聊天请求
                $self->heartAskReq($cli); //发送心跳
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
            $msg = array('data'=>'game start');
            $data = \App\Game\Core\Packet::packEncode($msg, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::SUB_GAME_START_REQ);
            $cli->push($data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * 发送叫地主请求
     * @param $cli
     * @param int $status  0表示不叫地主, 1表示叫地主
     */
    protected function gameCallReq($cli, $status = 0) {
        if($cli->connected) {
            $data = array('type'=>$status);
            $data = \App\Game\Core\Packet::packEncode($data, \App\Game\Conf\MainCmd::CMD_GAME, \App\Game\Conf\SubCmd::SUB_GAME_CALL_REQ);
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
            \App\Game\Core\Log::show("开始出牌:");
            if($is_first_round) {
                $status = 1;
                $card = array(array_shift($this->hand_card));   //第一张牌, 打出去
            } else {
                //跟牌默认过牌, TODO:需要实现跟牌逻辑, 需要从自己手牌中找出打过上次牌的牌, 根据情况决定是否跟牌
                $status = 0;   //出牌状态随机
                $card = array();
            }
            $msg = array(
                'status' => $status, //打牌还是过牌, 1跟牌, 0是过牌
                'chair_id' => $this->chair_id,
                'card' => $card,
            );
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
        \App\Game\Core\Log::show("登录成功, 开始游戏请求");
        $this->gameStartReq($cli);
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
        if($data['is_game_over']) {
            \App\Game\Core\Log::show('游戏结束');
            //触发开始游戏
            $this->gameStartReq($cli);
        } else {
            \App\Game\Core\Log::show('进入房间成功数据:'.json_encode($data));
            //保存用户信息和手牌信息
            $this->chair_id = $data['chair_id'];
            $this->hand_card = $data['card'];
            $this->my_room_info = $data;
            //如果没有叫地主, 触发叫地主动作
            if(!isset($data['calltype'])) {
                //根据自己的牌是否可以发送是否叫地主,  0,不叫, 1,叫地主, 2, 抢地主
                $obj = new \App\Game\Core\DdzPoker();
                $ret = $obj->isGoodCard($this->hand_card);
                $status = $ret ? 1 : 0;
                //发送是否叫地主操作
                $this->gameCallReq($cli, $status);
            }
            //是否轮到自己出牌, 如果是, 请出牌
            if(isset($data['index_chair_id']) && $data['index_chair_id'] == $this->chair_id) {
                if (isset($data['is_first_round']) && $data['is_first_round']) {
                    //首轮出牌
                    \App\Game\Core\Log::show('请出牌');
                } else {
                    //跟牌操作
                    \App\Game\Core\Log::show('请跟牌');
                }
                $this->outCardReq($cli, $data['is_first_round']);
            }
        }
    }

    /**
     * 自己叫完地主提示响应
     * @param $cli
     */
    protected function gameCallResp($cli, $data) {
        \App\Game\Core\Log::show('叫地主成功提示:'.json_encode($data));
    }

    /**
     * 叫完地主广播提示
     * @param $cli
     */
    protected function gameCallTipsResp($cli, $data) {
       $tips = $data['calltype'] ? $data['account'].'叫地主' : $data['account'].'不叫';
        \App\Game\Core\Log::show('广播叫地主提示:'.$tips);
    }

    /**
     * 触发游戏开始
     * @param $cli
     */
    protected function catchGameCardResp($cli, $data) {
        $tips = $data['user'].'摸底牌'.$data['hand_card'];
        \App\Game\Core\Log::show('摸底牌广播:'.$tips);
        if(isset($data['chair_id']) && $data['chair_id'] == $this->chair_id) {
            //合并手牌
            $hand_card = json_decode($data['hand_card'], true);
            $this->hand_card = $this->getDdzObj()->_sortCardByGrade(array_merge($this->hand_card, $hand_card));
            \App\Game\Core\Log::show('地主['.$this->account.']出牌:'.json_encode($this->hand_card));
            //地主首次出牌
            $this->outCardReq($cli, true);
        }
    }

    /**
     * 出牌提示
     * @param $cli
     */
    protected function gameOutCard($cli, $data) {
        \App\Game\Core\Log::show('出牌提示:'.json_encode($data));
        //移除手牌
        if(isset($data['status']) == 0 && isset($data['data']['card'])) {
            $this->hand_card = array_unique(array_values(array_diff($this->hand_card, $data['data']['card'])));
        }
    }

    /**
     * 出牌广播
     * @param $cli
     * @param $data
     */
    protected function gameOutCardResp($cli, $data) {
        \App\Game\Core\Log::show('出牌广播提示:'.json_encode($data));
        if(isset($data['is_game_over']) && $data['is_game_over']) {
            $tips = '广播:游戏结束,'.$data['account'].'胜利, 请点击"开始游戏",进行下一轮游戏';
            \App\Game\Core\Log::show($tips);
            //触发开始游戏
            $this->gameStartReq($cli);
        } else {
            $play = (isset($data['show_type']) && $data['show_type'] == 1) ? '跟牌': '过牌';
            $play = (isset($data['last_card']) && empty($data['last_card'])) ? '出牌' : $play;
            $last_card = !empty($data['last_card']) ? json_encode($data['last_card']) : '无';
            $out_card =  !empty($data['card']) ? json_encode($data['card']) : '无';
            $tips = '广播: 第'.$data['round'].'回合,第'.$data['hand_num'].'手出牌, '.$data['account'].$play.', 上次牌值是'.$last_card.', 本次出牌值是'.$out_card .', 本次出牌牌型'.$data['card_type'];
            \App\Game\Core\Log::show($tips);
            //下次出牌是否轮到自己, 轮到自己, 请出牌
            if(isset($data['next_chair_id']) && $data['next_chair_id'] == $this->chair_id) {
                //出牌请求, 默认过牌操作
                if (isset($data['is_first_round']) && $data['is_first_round']) {
                    //首轮出牌
                    \App\Game\Core\Log::show('请出牌');
                    //地主首次出牌
                } else {
                    //跟牌操作
                    \App\Game\Core\Log::show('请跟牌');
                    //地主首次出牌
                }
                $this->outCardReq($cli, $data['is_first_round']);
            }
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
