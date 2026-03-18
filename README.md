# YJB API - 客户端API、代理API、外部API

## 项目说明
纯HTTP API项目，负责客户端API、代理API和外部API服务

## 端口
8787

## 主要功能
- **客户端API** (`/api/v1/*`) - 玩家登录、充值、提现、游戏启动、抽奖等
- **代理API** (`/agent/api/*`) - 代理登录、数据统计、推广功能
- **外部API** (`/external/*`) - 第三方平台接入（Line、Talk等）

## 项目结构
```
yjb_api/
├── addons/webman/model/    # 数据模型（120个）
├── app/
│   ├── api/controller/     # API控制器
│   │   ├── v1/            # 客户端API
│   │   ├── agent/         # 代理API
│   │   └── external/      # 外部API
│   ├── service/           # 业务服务
│   ├── middleware/        # 中间件
│   └── functions.php      # 全局函数
└── config/                # 配置文件
```

## 说明
- 后台任务、定时任务、WebSocket进程已迁移到 **yjb_worker** 项目
- 本项目专注于HTTP API服务

## 启动
```bash
# Windows
php windows.php start

# Linux
php start.php start -d
```
