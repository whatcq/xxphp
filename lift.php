<?php
/**
 * 电梯调度程序demo
 * @author: cqiu
 * @time 2018-12-9
 */

$max = 10;
$min = 1;

$dataFile = 'lift.data';
$logFile = 'lift.log';

### lift-client #############
if (PHP_SAPI !== 'cli') {
    if (isset($_GET['q'])) {
        //try twice
        @file_put_contents($dataFile, $_GET['q'] . "\r\n", FILE_APPEND)
        or @file_put_contents($dataFile, $_GET['q'] . "\r\n", FILE_APPEND);
    }
?>
<title>电梯</title>
<style>
    div {
        width: 32px;
        float: left;
    }
    a {
        border: 1px solid #f4f4f4;
        width: 30px;
        height: 30px;
        background: pink;
        display: block;
        text-align: center;
        text-decoration: none;
    }
</style>
<?php
    for ($i = $min; $i <= $max; $i++) {
        echo <<<BTN
	<div>
		<a href="?q=$i++">$i</a>
		<a href="?q=$i 0">x</a>
		<a href="?q=$i 1">⇧</a>
		<a href="?q=$i -1">⇩</a>
	</div>
BTN;
    }
    exit;
}

### lift-server #############
//电梯初始化状态
$floor = 1;
$direction = 0;
//$speed = 0;
$weight = 0;//todo

/*
用什么样的数据结构来表示这个需求好一点呢。二进制？矢量？
*/
//$gos = [];
//$downs = $ups = array_fill($min, $max, 0);
$req = array_fill($min, $max, [-1 => 0, 0 => 0, 1 => 0]);

function _log($msg)
{
    file_put_contents($GLOBALS['logFile'], date('H:i:s') . " $msg\r\n", FILE_APPEND);
}

file_put_contents($GLOBALS['logFile'], '');
_log('Lift start server! #F' . $floor);
register_shutdown_function(function () use ($floor) {
    _log('Lift stop! #F' . $floor);
});

function getRequirement()
{
    global $dataFile;
    if (!is_file($dataFile)) return null;
    $handle = fopen($dataFile, 'r+');
    $contents = '';
    while (!feof($handle)) {
        $contents .= fread($handle, 8192);
    }
    while (true) {
        if (flock($handle, LOCK_EX)) { // 进行排它型锁定
            ftruncate($handle, 0); // truncate file
            fwrite($handle, '');
            fflush($handle); // flush output before releasing the lock
            flock($handle, LOCK_UN); // 释放锁定
            break;
        }
    }
    fclose($handle);
    return $contents;
}

define('UP', 1);
define('DOWN', -1);
//define('GO', 0);
function getNextTask()
{
    global $floor, $direction, $max, $min;
    //1s之内的请求，先去接最近的，不按两者先后顺序
    if ($direction === 0) {
        //getNearGoFloor
        $targetFloor[UP] = searchReq(0, UP, $floor);
        $targetFloor[DOWN] = searchReq(0, DOWN, $floor);
        if ($targetFloor[UP] > 0 && $targetFloor[DOWN] > 0) {
            $NearGoFloor = min($targetFloor[UP], $targetFloor[DOWN]);
        } else {
            $NearGoFloor = $targetFloor[UP] + $targetFloor[DOWN];
        }
        if ($NearGoFloor > 0) return $NearGoFloor;

        //getNearDirectionFloor
        //assert($targetFloor[UP]+ $targetFloor[DOWN] === 0);

        $t[DOWN][DOWN . DOWN] = searchReq(DOWN, DOWN, $floor);//下楼顺向
        $t[UP][UP . UP] = searchReq(UP, UP, $floor);//上楼顺向

        if ($t[DOWN][DOWN . DOWN]) {
            /* todo get最近的
             * if($t[UP][UP.UP]){
                return min($t[DOWN][DOWN.DOWN], $t[UP][UP.UP]);
            }*/
            return $t[DOWN][DOWN . DOWN];
        } else {
            if ($t[UP][UP . UP]) {
                return $t[UP][UP . UP];
            }//...
        }

        $t[DOWN]['max'] = searchReq(DOWN, DOWN, $max + 1);//最高下楼请求 > floor
        $t[UP]['min'] = searchReq(UP, UP, $min - 1);//最低上楼请求 < floor
        if ($t[DOWN]['max']) {
            if ($t[UP]['min']) {
                if ($floor - $t[UP]['min'] > $t[DOWN]['max'] - $floor) {
                    return $t[DOWN]['max'];
                } elseif ($floor - $t[UP]['min'] < $t[DOWN]['max'] - $floor) {
                    return $t[UP]['min'];
                } else {
                    return $floor > ($max - $min) / 2 ? $t[DOWN]['max'] : $t[UP]['min'];
                }
            }
            return $t[DOWN]['max'];
        } else {
            if ($t[UP]['min']) return $t[UP]['min'];
        }

        return 0;
    } else {
        /*
        | 下 | 到 | 上 |
        | 0 | 0 | 0 |
        | 0 | 1 | 2 |
        | 0 | 0 | 0 |
        | 3 | x | 0 |
        | 0 | 0 | 0 |
        | 0 | 4 | 5 |
        | 0 | 0 | 0 |
        */
        //内同向最近
        if ($targetFloor = searchReq(0, $direction, $floor)) {
            return $targetFloor;
        }
        //外同向最近
        if ($targetFloor = searchReq($direction, $direction, $floor)) {
            return $targetFloor;
        }
        //外逆向 （同向）最远 ||（逆向）最近
        if ($targetFloor = searchReq(-$direction, -$direction, $direction > 0 ? $max + 1 : $min - 1)) {
            return $targetFloor;
        }
        //内掉头要去
        if ($targetFloor = searchReq(0, -$direction, $floor)) {
            return $targetFloor;
        }
        //外同向最远
        if ($targetFloor = searchReq($direction, $direction, $direction > 0 ? $min - 1 : $max + 1)) {
            return $targetFloor;
        }

    }
    return 0;
}

/**
 * @param int $requireType 请求类型 -1 0 1
 * @param int $searchDirection 查找方向 -1 1
 * @param $searchStart
 * @ param string $near_far 最大还是最小 near far
 * @return int target_floor|0 目标楼层，没有则为0
 */
function searchReq($requireType, $searchDirection, $searchStart)
{
    global $req;
    $i = $searchStart;
    while (isset($req[($i += $searchDirection)][$requireType])) {
        if ($req[$i][$requireType]) {
            return $i;
        }
    }
    return 0;
}

$m = 0;
while (true) {
    // getReq
    if ($contents = getRequirement()) {
        _log($contents);
        foreach (explode("\n", $contents) as $requirement) {
            if (!$requirement = trim($requirement, "\r")) continue;
            //var_dump($requirement);
            list($_floor, $_direction) = explode(' ', $requirement);
            $_floor = (int)$_floor;

            if (empty($_direction)) {
                $req[$_floor][0] = is_numeric($_direction) ? 0 : 1;
            } else {
                if ($_direction > 0) {
                    $req[$_floor][UP] = 1;
                } else {
                    $req[$_floor][DOWN] = 1;
                }
            }
        }
    }
    $act = null;
    // arrive,pick_up => open
    if ($req[$floor][0] || $req[$floor][$direction]) {
        $act = 'open';
        $req[$floor][0] = $req[$floor][$direction] = 0;
    } else {
        $targetFloor = getNextTask();
        if ($targetFloor) {
            if ($targetFloor === $floor) {
                $direction = -$direction;
                $act = 'open';
                $req[$floor][$direction] = 0;
            } else {
                $act = 'move';
                $direction = $targetFloor > $floor ? 1 : -1;
            }
        } else {
            //stop
            $direction = 0;
        }
    }
    // act = move/open/close
    if ($direction != 0 && $act === 'move') {
        $floor += $direction;
        _log($floor);
        sleep(1);
        continue;
    }
    if ($act === 'open') { // and close
        _log('open F#' . $floor . ' ' . $direction);
        sleep(1);
        continue;
    }
}

