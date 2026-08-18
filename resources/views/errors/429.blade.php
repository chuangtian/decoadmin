@extends('errors.layout')

@section('title', '请求过于频繁')
@section('code', '429')
@section('heading', '操作太频繁了')
@section('message', '系统暂时限制了当前请求，请稍等片刻后再试。')
