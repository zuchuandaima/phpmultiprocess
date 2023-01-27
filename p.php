<?php
if(php_sapi_name() != 'cli'){
    return;
}

// 系统启动时间
define('PROCESS_LIB_SYSTEM_UPTIME', get_sysuptime());
// 父进程启动时间
define('PARENT_PORCESS_UPTIME', get_ppuptime(get_ppid()));

// 清理过期资源(文件和SEM信号锁)，每次程序执行都需要调用，清除掉之前执行时的残留文件。
clear_process_resource();


// 获取父进程PID
function get_ppid(){
    // 这里需要识别出是在子进程中调用还是在父进程中调用，不同的形式，保存的变量内容的文件位置需要保持一致
    $ppid = posix_getppid();
    // 理论上，这种判断方式可能会出坑。但在实际使用中，除了fork出的子进程外，不太可能让PHP进程的父进程的程序名中出现php字样。
    if(strpos(readlink("/proc/{$ppid}/exe"), 'php') === FALSE){
        $pid = getmypid();
    }else{
        $pid = $ppid;
    }
    return $pid;
}


// 判断是否在子进程中
function is_subprocess(){
    if(getmypid() != get_ppid()){
        return true;
    }else{
        return false;
    }
}


// 多进程计数
// 编译PHP时需要使用参数--enable-sysvmsg安装所需的模块
// shm_*系列函数的操作不够灵活
function mp_counter($countername, $update = true){

    $this_counter_path = get_counter_path($countername);

    // 更新计数，先锁定
    $lock = sem_lock('mp_counter_'.$countername);

    if(!file_exists($this_counter_path)){
        $new_counter = $current_counter = 0;
    }else{
        $current_counter = file_get_contents($this_counter_path);
        $new_counter = $current_counter + 1;
    }

    if($update){
        file_put_contents($this_counter_path, $new_counter);
    }

    sem_unlock($lock);
    if($update){
        return $new_counter;
    }else{
        return $current_counter;
    }
    
}

// 清除记数
function reset_counter($countername){
    $lock = sem_lock('mp_counter_'.$countername);
    $this_counter_path = get_counter_path($countername);
    @unlink($this_counter_path);
    sem_unlock($lock);
}

// 返回记数文件
function get_counter_path($countername){
    // 父进程PID
    $top_pid = get_ppid();

    // 由父进程ID确定变量文件路径前缀
    $path_pre = get_process_file_dir()."mp_counter_{$countername}_pid_{$top_pid}_";

    // 由于系统启动时间和当前父进程启动时间(jiffies格式)确定计数使用的文件
    $this_counter_path = "{$path_pre}btime_".PROCESS_LIB_SYSTEM_UPTIME."_ptime_".PARENT_PORCESS_UPTIME;

    return $this_counter_path;
}


// 创建多进程
// 编译PHP时需要使用参数--enable-pcntl安装所需的模块
// $show_message 0不显示，1只显示一次，2每次fork都显示一次
function multi_process($num=1, $show_message=1){
    // 子进程数量
    $child = 0;

    // 任务完成标识
    $task_finish = FALSE;

    // 清空子进程退出状态
    unset($status);

    if($show_message == 1){
        if($num==1){
            $process_num_str = 'process';
        }else{
            $process_num_str = 'processes';
        }
        echo "\33[7m\33[32m[*] {$num} {$process_num_str} will be Forked\33[0m\n";
    }

    while(TRUE) {
        // 如果任务未完成，并且子进程数量没有达到最高，则创建
        if ($task_finish == FALSE && $child < $num) {
            // 子进程在这里创建，只有这之后的代码才会在子进程运行，上面的都只在父进程运行
            $pid = pcntl_fork();
            if ($pid > 0) {
                // pid > 0，这里是父进程
                // 这行信息是父进程输出的，而不是子进程
                $child++;
                if($show_message == 2){
                    echo "\33[7m\33[32m[+] New Process Forked: {$pid} ($child)\33[0m\n";
                }
            } elseif($pid == 0) {
                // pid == 0，这里是子进程
                // 直接返回，继续处理主程序的while(is_subprocess()){}循环部分。
                // 在这之后的代码也不会在子进程运行
                return;
            }else{
                // 创建失败
                continue;
            }
        }

        // 子进程管理部分。子进程在上面已经直接return了，下面的代码只在父进程才会运行。
        if($task_finish){
            // 如果任务已经完成
            if ($child > 0) {
                // 如果还有子进程未退出，等待子进程退出
                pcntl_wait($status);
                $child--;
            } else {
                // 所有子进程退出，父进程跳出循环，继续执行while(is_subprocess()){}循环之后的代码
                return;
            }
        }else{
            // 如果任务未完成
            if($child >= $num){
                // 子进程已经达到数量，等待子进程退出
                pcntl_wait($status);
                $child--;
            }else{
                // 子进程没有达到数量，不做任何操作，直接下一下循环继续创建
            }
        }
        // 父进程调用pcntl_wait()之后会等待子进程退出，所以这里并不会导致父进程不停的循环。

        // 子进程退出状态码为9时，则标记为所有任务完成，然后等待所有子进程退出
        if(!empty($status) && pcntl_wexitstatus($status) == 9){
            $task_finish = TRUE;
        }
    }
}


// 多进程运行时，以父进程为基准，父进程和子进程使用同一个锁。
function sem_lock($lock_name=NULL){
    $pid = get_ppid();
    if(empty($lock_name)){
        $lockfile = get_process_file_dir()."sem_keyfile_main_pid_{$pid}";
    }else{
        $lockfile = get_process_file_dir()."sem_keyfile_{$lock_name}_pid_{$pid}";
    }
    if(!file_exists($lockfile)){
        touch($lockfile);
    }
    $shm_id = sem_get(ftok($lockfile, 'a'), 1, 0600, true);
    if(sem_acquire($shm_id)){
        return $shm_id;
    }else{
        return FALSE;
    }
}

// 解除锁
function sem_unlock($shm_id){
    sem_release($shm_id);
}

// 清理资源(文件和SEM信号锁)
function clear_process_resource(){

    // 清除sem的文件和信号量
    $files = glob(get_process_file_dir()."sem_keyfile*pid_*");
    foreach($files as $file){
        preg_match("/pid_(\d*)/", $file, $preg);
        $pid = $preg[1];
        $exe_path = "/proc/{$pid}/exe";
        // 如果文件不存在则说明进程不存在，判断是否为PHP进程，排除php-fpm进程
        if(!file_exists($exe_path)
            || stripos(readlink($exe_path), 'php') === FALSE
            || stripos(readlink($exe_path), 'php-fpm') === TRUE){
            $sem = @sem_get(@ftok($file, 'a'));
            if($sem){
                @sem_remove($sem);
            }
            @unlink($file);
        }
    }

    // 清除mp_counter的文件（仅此类型文件不可重用，所以严格处理，匹配系统启动时间和进程启动时间）
    // 更新，系统启动时间会变动，取消掉比较系统启动时间的条件 $btime != PROCESS_LIB_SYSTEM_UPTIME
    $files = glob(get_process_file_dir()."mp_counter*");
    foreach($files as $file){
        preg_match("/pid_(\d*)_btime_(\d*)_ptime_(\d*)/", $file, $preg);
        $pid = $preg[1];
        $btime = $preg[2];
        $ptime = $preg[3];
        $exe_path = "/proc/{$pid}/exe";

        // 清除文件
        if(
            !file_exists($exe_path)
            || stripos(readlink($exe_path), 'php') === FALSE
            || stripos(readlink($exe_path), 'php-fpm') === TRUE
        ){
            @unlink($file);
        }
    }
}

// 系统启动时间
function get_sysuptime(){
    preg_match("/btime (\d+)/", file_get_contents("/proc/stat"), $preg);
    return $preg[1];
}

// 如果是在子进程中调用，则取父进程的启动时间。如果不是在子进程中调用，则取自身启动时间。时间都是jiffies格式。
function get_ppuptime($pid){
    $stat_sections = explode(' ', file_get_contents("/proc/{$pid}/stat"));
    return $stat_sections[21];
}

function get_process_file_dir(){
    // 文件保存目录，/dev/shm/是内存空间映射到硬盘上，IO速度快。
    // 有些环境上可能会没有这个目录，比如OpenVZ的VPS，这个路径实际是在硬盘上
    if(file_exists('/dev/shm/') && is_dir('/dev/shm/')){
        $process_file_dir = '/dev/shm/';
    }else{
        $process_file_dir = '/tmp/';
    }
    return $process_file_dir;
}
