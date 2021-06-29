# TG_Private_BOT
telegram bot的api，可以下载你自己转发的视频、文件、图片到本地

# 如何使用
* 为了方便使用，已经用单文件写法，下载后保证能通过url进行公网访问即可
* 推荐自己搭建telegram bot api的local server版本，不然无法下载超过20M的文件
* 需要参照代码注释，修改页首的所有const参数
* 无论是否搭建local server，都需要在你需要使用的API服务器设置webhook（访问`API_URl/setWebHook?url=(tg.php对应的URL)`）

# 如何搭建telegram bot api(local server)
* 参照官方文档，https://core.telegram.org/bots/api#using-a-local-bot-api-server

# 注意事项
* 如果使用过官方的api服务，需要先logOut
* 注意各目录的权限问题，如果自己搭建了local server，推荐使用和php一样的用户去运行
