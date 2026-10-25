// CI pipeline: all stages (Checkout -> Test -> Build & Push -> Update GitOps)
// are defined and enforced by the global shared library "malina-ci":
// infr-as-a-code/jenkins/shared-library/vars/ciPipeline.groovy
// Contract: infr-as-a-code/specs/002-tests-before-deploy/contracts/pipeline.md
// NOTE: requires the shared library on infr-as-a-code main + Jenkins reload
// (spec 002, phase 2) — do not merge this change before that lands.
ciPipeline(
    app: 'demo-symfony-k3s',
    testImage: 'php:8.5-cli',
    testDeps: [image: 'composer:2', command: 'composer install --no-interaction --prefer-dist --no-progress'],
    gitopsValues: 'clusters/malina/values/demo-symfony.yaml',
    deploy: [namespace: 'demo', deployment: 'demo-symfony', argoApp: 'demo-symfony'],   // spec 005
)
