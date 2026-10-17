// CI pipeline: merge to main -> Kaniko build -> ghcr.io -> bump GitOps tag.
// Contract: infr-as-a-code/specs/001-k3s-cicd-platform/contracts/pipeline.md
pipeline {
    agent {
        kubernetes {
            defaultContainer 'git'
            yaml '''
apiVersion: v1
kind: Pod
metadata:
  labels: { app: jenkins-agent-demo-symfony }
spec:
  restartPolicy: Never
  containers:
    - name: kaniko
      image: gcr.io/kaniko-project/executor:v1.24.0-debug
      command: ["/busybox/sleep"]
      args: ["99d"]
      volumeMounts:
        - name: ghcr-config
          mountPath: /kaniko/.docker
    - name: git
      image: alpine/git:2.49.1
      command: ["sleep"]
      args: ["99d"]
      env:
        - name: GITHUB_USER
          valueFrom: { secretKeyRef: { name: jenkins-github, key: username } }
        - name: GITHUB_TOKEN
          valueFrom: { secretKeyRef: { name: jenkins-github, key: token } }
  volumes:
    - name: ghcr-config
      secret:
        secretName: ghcr-push
        items:
          - key: .dockerconfigjson
            path: config.json
'''
        }
    }

    options {
        disableConcurrentBuilds()
        timestamps()
    }

    stages {
        stage('Checkout') {
            steps {
                container('git') {
                    checkout scm
                    script {
                        env.IMAGE_TAG = sh(returnStdout: true, script: 'git rev-parse --short HEAD').trim()
                        env.IMAGE = 'ghcr.io/piotrwojewoda/demo-symfony-k3s'
                        echo "Building ${env.IMAGE}:${env.IMAGE_TAG}"
                    }
                }
            }
        }

        stage('Build & Push image') {
            steps {
                container('kaniko') {
                    sh '''
                        /kaniko/executor \
                          --context="${WORKSPACE}" \
                          --dockerfile=Dockerfile \
                          --destination="${IMAGE}:${IMAGE_TAG}" \
                          --destination="${IMAGE}:latest"
                    '''
                }
            }
        }

        stage('Bump GitOps repository') {
            steps {
                container('git') {
                    sh '''
                        set -e
                        git clone "https://x-access-token:${GITHUB_TOKEN}@github.com/piotrwojewoda/infr-as-a-code.git" infra
                        cd infra
                        sed -i "s|^  tag: .*|  tag: \\"${IMAGE_TAG}\\"|" clusters/malina/values/demo-symfony.yaml
                        git config user.name "Jenkins CI"
                        git config user.email "jenkins@kreciowo.pl"
                        git add clusters/malina/values/demo-symfony.yaml
                        if git diff --cached --quiet; then
                            echo "Tag already up to date - nothing to commit."
                        else
                            git commit -m "chore(release): demo-symfony-k3s ${IMAGE_TAG}"
                            git push origin main
                        fi
                    '''
                }
            }
        }
    }

    post {
        success { echo "Released ${IMAGE}:${IMAGE_TAG} - ArgoCD will roll it out to https://demo.kreciowo.pl" }
        failure { echo 'Pipeline failed - the running deployment was NOT touched.' }
    }
}
