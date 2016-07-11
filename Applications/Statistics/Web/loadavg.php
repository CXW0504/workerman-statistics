<?php
/**
 * Created by PhpStorm.
 * User: bandit
 * Date: 2016/7/11
 * Time: 23:36
 */
include_once __DIR__.'/../Clients/StatisticClient.php';
echo StatisticClient::report('total','memory',true,rand(200,500),'memory info','',memory_get_usage());