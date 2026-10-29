-- 043 社区实验室：功能开关种子

INSERT INTO lab_features (feature_key, name, description, enabled, rollout, sort, created_at) VALUES
    ('offline-cache', '离线缓存', '预缓存页面与资源，断网时也能打开已访问过的内容', 1, 'all', 10, NOW()),
    ('realtime-inbox', '实时消息', '消息中心与私信实时推送，无需手动刷新', 1, 'verified', 20, NOW()),
    ('hardcore-quiz', '硬核会员题库建设', '参与分分区题库出题与评审（硬核会员专享）', 1, 'flash', 30, NOW()),
    ('lab-experimental', '实验性界面', '提前体验仍在打磨中的界面改动（仅限邀请）', 0, 'invite', 40, NOW());

-- @@DOWN

DELETE FROM lab_features WHERE feature_key IN ('offline-cache', 'realtime-inbox', 'hardcore-quiz', 'lab-experimental');
