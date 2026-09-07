import { defineRailway, github, mysql, preserve, project, redis, service, volume } from "railway/iac";

export default defineRailway(() => {
  const Redis = redis("Redis", { region: "iad" });
  Redis.deploy = { startCommand: "/bin/sh -c \"rm -rf $RAILWAY_VOLUME_MOUNT_PATH/lost+found/ && exec docker-entrypoint.sh redis-server --requirepass $REDIS_PASSWORD --save 60 1 --dir $RAILWAY_VOLUME_MOUNT_PATH\"" };
  Redis.networking = { privateNetworkEndpoint: "redis" };
  const MySQL = mysql("MySQL", { region: "iad" });
  MySQL.deploy = { startCommand: "docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=1G" };
  MySQL.networking = { privateNetworkEndpoint: "mysql" };
  const redisVolume = volume("redis-volume", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "iad", sizeMB: 500 });
  const mysqlVolume = volume("mysql-volume", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "iad", sizeMB: 500 });
  const env = { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), BOOTSTRAP_ADMIN_EMAIL: preserve(), BOOTSTRAP_ADMIN_NAME: preserve(), BOOTSTRAP_ADMIN_PASSWORD: preserve(), CACHE_STORE: preserve(), DB_CONNECTION: preserve(), DB_DATABASE: preserve(), DB_HOST: preserve(), DB_PASSWORD: preserve(), DB_PORT: preserve(), DB_USERNAME: preserve(), FILESYSTEM_DISK: preserve(), QUEUE_CONNECTION: preserve(), REDIS_HOST: preserve(), REDIS_PASSWORD: preserve(), REDIS_PORT: preserve(), SESSION_DRIVER: preserve() };
  const web = service("web", { source: github("abdurrazzak2895-debug/web", { branch: "master", checkSuites: false }), replicas: { "iad": 1 }, env });
  web.build = "npm run build";
  web.deploy = { preDeployCommand: "bash railway/init-app.sh", healthcheckPath: "/login", healthcheckTimeout: 120, restartPolicyType: "ON_FAILURE", restartPolicyMaxRetries: 10 };
  const scheduler = service("scheduler", { source: github("abdurrazzak2895-debug/web", { branch: "master", checkSuites: false }), replicas: { "iad": 1 }, env });
  scheduler.deploy = { startCommand: "bash railway/run-scheduler.sh", restartPolicyType: "ON_FAILURE", restartPolicyMaxRetries: 10 };
  const worker = service("worker", { source: github("abdurrazzak2895-debug/web", { branch: "master", checkSuites: false }), replicas: { "iad": 1 }, env });
  worker.deploy = { startCommand: "bash railway/run-worker.sh", restartPolicyType: "ON_FAILURE", restartPolicyMaxRetries: 10 };
  const solverEnv = { CAPTCHA_SYNC_TOKEN: preserve(), CAPTCHA_SOLVER_API_TOKEN: preserve(), CAPTCHA_SOLVER_CONCURRENCY: preserve(), CAPTCHA_SOLVER_BROWSERS: preserve() };
  const solver = service("solver", { source: github("abdurrazzak2895-debug/web", { branch: "master", checkSuites: false }), replicas: { "iad": 1 }, env: solverEnv });
  solver.build = { dockerfilePath: "deploy/captcha-solver.Dockerfile" };
  solver.deploy = { healthcheckPath: "/health", healthcheckTimeout: 120, restartPolicyType: "ON_FAILURE", restartPolicyMaxRetries: 10 };
  return project("duronto-ipms", { resources: [Redis, MySQL, web, scheduler, worker, solver, redisVolume, mysqlVolume] });
});
