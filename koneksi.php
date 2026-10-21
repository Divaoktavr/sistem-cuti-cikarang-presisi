<?php
$host='127.0.0.1'; $db='db_cuti_karyawan'; $user='root'; $pass='';
try { $pdo=new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch(PDOException $e){ http_response_code(500); exit('Koneksi database gagal. Pastikan MySQL Laragon aktif dan database db_cuti_karyawan sudah diimpor.'); }
