import * as THREE from 'three';
import type { PageModule } from './types';

type Vec2 = { x: number; y: number };

const BASE_AZIMUTH = 0.36;
const BASE_POLAR = 1.38;
const MOBILE_RADIUS = 24.8;
const DESKTOP_RADIUS = 19.2;

function mulberry32(seed: number): () => number {
  return () => {
    seed |= 0;
    seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function glowTexture(): any {
  const canvas = document.createElement('canvas');
  canvas.width = 96;
  canvas.height = 96;
  const ctx = canvas.getContext('2d');

  if (ctx !== null) {
    const gradient = ctx.createRadialGradient(48, 48, 0, 48, 48, 48);
    gradient.addColorStop(0, 'rgba(255,255,255,1)');
    gradient.addColorStop(0.18, 'rgba(255,250,229,.92)');
    gradient.addColorStop(0.5, 'rgba(188,240,223,.34)');
    gradient.addColorStop(1, 'rgba(110,215,196,0)');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 96, 96);
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function storeSignTexture(): any {
  const canvas = document.createElement('canvas');
  canvas.width = 1024;
  canvas.height = 176;
  const ctx = canvas.getContext('2d');

  if (ctx !== null) {
    const gradient = ctx.createLinearGradient(0, 0, 1024, 176);
    gradient.addColorStop(0, '#0a564f');
    gradient.addColorStop(0.55, '#147568');
    gradient.addColorStop(1, '#0a4e4b');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, 1024, 176);
    ctx.strokeStyle = 'rgba(255,245,198,.5)';
    ctx.lineWidth = 5;
    ctx.strokeRect(12, 12, 1000, 152);
    ctx.fillStyle = '#fff4c9';
    ctx.font = '700 66px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.letterSpacing = '12px';
    ctx.fillText('HOSHI MART', 512, 72);
    ctx.font = '500 26px "Microsoft YaHei", Arial, sans-serif';
    ctx.letterSpacing = '5px';
    ctx.fillText('星マート  ·  24 HOURS', 512, 132);
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.anisotropy = 8;
  return texture;
}

function posterTexture(title: string, subtitle: string, background: string, ink: string): any {
  const canvas = document.createElement('canvas');
  canvas.width = 256;
  canvas.height = 356;
  const ctx = canvas.getContext('2d');

  if (ctx !== null) {
    ctx.fillStyle = background;
    ctx.fillRect(0, 0, 256, 356);
    ctx.fillStyle = ink;
    ctx.font = '700 34px "Microsoft YaHei", Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(title, 128, 64);
    ctx.font = '500 22px Arial, sans-serif';
    ctx.fillText(subtitle, 128, 112);
    ctx.fillStyle = 'rgba(255,255,255,.7)';
    ctx.fillRect(32, 148, 192, 4);
    ctx.beginPath();
    ctx.arc(128, 233, 65, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(255,255,255,.2)';
    ctx.fill();
    ctx.fillStyle = ink;
    ctx.font = '700 24px Arial, sans-serif';
    ctx.fillText('NEW', 128, 241);
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.anisotropy = 4;
  return texture;
}

function vendingTexture(): any {
  const canvas = document.createElement('canvas');
  canvas.width = 256;
  canvas.height = 420;
  const ctx = canvas.getContext('2d');

  if (ctx !== null) {
    ctx.fillStyle = '#f3efe1';
    ctx.fillRect(0, 0, 256, 420);
    ctx.fillStyle = '#c84f43';
    ctx.fillRect(0, 0, 256, 48);
    ctx.fillStyle = '#fff7e5';
    ctx.font = '700 22px Arial, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('DRINKS', 128, 32);

    const colors = ['#df6556', '#5aa3bd', '#e0b84f', '#72985a', '#d5779b', '#5e766f'];
    for (let row = 0; row < 4; row++) {
      for (let col = 0; col < 4; col++) {
        ctx.fillStyle = colors[(row + col) % colors.length] ?? '#df6556';
        ctx.fillRect(21 + col * 56, 70 + row * 61, 36, 43);
        ctx.fillStyle = 'rgba(255,255,255,.66)';
        ctx.fillRect(25 + col * 56, 76 + row * 61, 6, 30);
      }
    }

    ctx.fillStyle = '#20292b';
    ctx.fillRect(20, 330, 216, 58);
    ctx.fillStyle = '#b8ffd4';
    ctx.fillRect(188, 340, 28, 18);
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function reflectionTexture(): any {
  const canvas = document.createElement('canvas');
  canvas.width = 256;
  canvas.height = 256;
  const ctx = canvas.getContext('2d');

  if (ctx !== null) {
    ctx.clearRect(0, 0, 256, 256);
    const colors = ['rgba(255,211,116,.58)', 'rgba(101,227,209,.46)', 'rgba(255,245,205,.28)'];

    for (let i = 0; i < 18; i++) {
      const x = 15 + (i * 47) % 226;
      const width = 4 + (i % 4) * 3;
      const gradient = ctx.createLinearGradient(0, 0, 0, 256);
      gradient.addColorStop(0, colors[i % colors.length] ?? colors[0]!);
      gradient.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = gradient;
      ctx.fillRect(x, 0, width, 256 - (i % 5) * 18);
    }
  }

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function addBox(
  parent: any,
  size: [number, number, number],
  position: [number, number, number],
  material: any,
  castShadow = false,
): any {
  const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size), material);
  mesh.position.set(...position);
  mesh.castShadow = castShadow;
  mesh.receiveShadow = true;
  parent.add(mesh);
  return mesh;
}

function rod(start: any, end: any, radius: number, material: any): any {
  const direction = end.clone().sub(start);
  const length = direction.length();
  const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius, radius, length, 7), material);
  mesh.position.copy(start).add(end).multiplyScalar(0.5);
  mesh.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), direction.normalize());
  mesh.castShadow = true;
  return mesh;
}

function createSky(scene: any, glow: any): void {
  const sky = new THREE.Mesh(
    new THREE.SphereGeometry(70, 32, 20),
    new THREE.ShaderMaterial({
      side: THREE.BackSide,
      depthWrite: false,
      uniforms: {
        topColor: { value: new THREE.Color(0x050b15) },
        middleColor: { value: new THREE.Color(0x142431) },
        horizonColor: { value: new THREE.Color(0x744b45) },
        bottomColor: { value: new THREE.Color(0x080d11) },
      },
      vertexShader: `
        varying vec3 vDirection;
        void main() {
          vec4 world = modelMatrix * vec4(position, 1.0);
          vDirection = normalize(world.xyz);
          gl_Position = projectionMatrix * viewMatrix * world;
        }
      `,
      fragmentShader: `
        varying vec3 vDirection;
        uniform vec3 topColor;
        uniform vec3 middleColor;
        uniform vec3 horizonColor;
        uniform vec3 bottomColor;

        void main() {
          float y = vDirection.y;
          vec3 color = mix(bottomColor, horizonColor, smoothstep(-0.12, 0.06, y));
          color = mix(color, middleColor, smoothstep(0.06, 0.3, y));
          color = mix(color, topColor, smoothstep(0.25, 0.82, y));
          float cityGlow = pow(max(0.0, 1.0 - abs(y - 0.015) * 8.0), 2.0);
          color += vec3(0.12, 0.055, 0.028) * cityGlow;
          gl_FragColor = vec4(color, 1.0);
        }
      `,
    }),
  );
  sky.frustumCulled = false;
  scene.add(sky);

  const stars = new Float32Array(150 * 3);
  const random = mulberry32(44821);

  for (let i = 0; i < 150; i++) {
    const theta = random() * Math.PI * 2;
    const y = 10 + random() * 36;
    const r = Math.sqrt(Math.max(1, 58 * 58 - y * y));
    stars[i * 3] = Math.cos(theta) * r;
    stars[i * 3 + 1] = y;
    stars[i * 3 + 2] = Math.sin(theta) * r;
  }

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(stars, 3));
  scene.add(new THREE.Points(
    geometry,
    new THREE.PointsMaterial({
      color: 0xd5e3e2,
      size: 0.08,
      transparent: true,
      opacity: 0.56,
      depthWrite: false,
    }),
  ));

  const moonGlow = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: glow,
      color: 0xffdfa7,
      transparent: true,
      opacity: 0.25,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    }),
  );
  moonGlow.position.set(-22, 18, -30);
  moonGlow.scale.set(12, 12, 1);
  scene.add(moonGlow);
}

function createNeighbourhood(scene: any, random: () => number): void {
  const wallColors = [0x152029, 0x1b252d, 0x101920, 0x24292c];
  const windowMaterial = new THREE.MeshBasicMaterial({
    color: 0xe4be72,
    transparent: true,
    opacity: 0.5,
    toneMapped: false,
  });
  const darkWindow = new THREE.MeshStandardMaterial({
    color: 0x111b23,
    roughness: 0.38,
    metalness: 0.15,
  });

  const blocks = [
    [-12, 4.4, -5.5, 6.2, 8.8, 7.2],
    [16.5, 4.2, -9.2, 7.0, 8.4, 7.2],
    [-19, 3.2, -12, 8.5, 6.4, 8],
    [22, 3.7, -13, 9.2, 7.4, 9],
    [0, 5.2, -15, 13, 10.4, 5],
  ];

  blocks.forEach((block, index) => {
    const [x, y, z, w, h, d] = block as [number, number, number, number, number, number];
    const material = new THREE.MeshStandardMaterial({
      color: wallColors[index % wallColors.length],
      roughness: 0.93,
    });
    addBox(scene, [w, h, d], [x, y, z], material);

    for (let row = 0; row < Math.floor(h / 1.65); row++) {
      for (let col = 0; col < Math.floor(w / 1.7); col++) {
        const lit = random() > 0.5;
        const windowMesh = addBox(
          scene,
          [0.65, 0.82, 0.05],
          [x - w / 2 + 1 + col * 1.7, 1.25 + row * 1.55, z + d / 2 + 0.03],
          lit ? windowMaterial : darkWindow,
        );
        windowMesh.receiveShadow = false;
      }
    }
  });

  const distantMaterial = new THREE.MeshBasicMaterial({ color: 0x0d171d });

  for (let i = 0; i < 28; i++) {
    const x = -34 + i * 2.5;
    const height = 4 + random() * 10;
    addBox(scene, [2.1, height, 2.5], [x, height / 2, -24 - random() * 7], distantMaterial);
  }
}

function createStreet(scene: any, glow: any, random: () => number): any[] {
  const asphalt = new THREE.MeshStandardMaterial({
    color: 0x171d20,
    roughness: 0.28,
    metalness: 0.22,
  });
  const pavement = new THREE.MeshStandardMaterial({
    color: 0x5e625f,
    roughness: 0.68,
    metalness: 0.06,
  });
  const curbMaterial = new THREE.MeshStandardMaterial({ color: 0xa6a69d, roughness: 0.8 });
  const lineMaterial = new THREE.MeshBasicMaterial({ color: 0xd5d4c6, transparent: true, opacity: 0.58 });

  const ground = new THREE.Mesh(new THREE.PlaneGeometry(86, 56), asphalt);
  ground.rotation.x = -Math.PI / 2;
  ground.position.set(0, -0.22, 15);
  ground.receiveShadow = true;
  scene.add(ground);

  addBox(scene, [22, 0.28, 6.8], [0, -0.02, 4.2], pavement);
  addBox(scene, [22, 0.3, 0.34], [0, 0.18, 7.72], curbMaterial);

  for (let i = 0; i < 10; i++) {
    addBox(scene, [0.035, 0.015, 3.0], [-9 + i * 2, 0.15, 5.2], lineMaterial);
  }

  for (let i = 0; i < 7; i++) {
    addBox(scene, [4.2, 0.018, 0.68], [0, -0.202, 8.25 + i * 0.96], lineMaterial);
  }

  for (let i = 0; i < 11; i++) {
    addBox(scene, [1.6, 0.015, 0.09], [-17 + i * 3.2, -0.195, 18.2], lineMaterial);
  }

  const drain = new THREE.Group();
  drain.position.set(-4.2, 0.16, 6.7);
  addBox(drain, [1.1, 0.05, 0.72], [0, 0, 0], new THREE.MeshStandardMaterial({ color: 0x242a2c, roughness: 0.5, metalness: 0.4 }));
  for (let i = 0; i < 6; i++) {
    addBox(drain, [0.035, 0.055, 0.62], [-0.44 + i * 0.176, 0.01, 0], new THREE.MeshBasicMaterial({ color: 0x0d1112 }));
  }
  scene.add(drain);

  for (let i = 0; i < 5; i++) {
    const puddle = new THREE.Mesh(
      new THREE.CircleGeometry(1.7 + random() * 1.7, 36),
      new THREE.MeshPhysicalMaterial({
        color: 0x172126,
        roughness: 0.09,
        metalness: 0.52,
        transparent: true,
        opacity: 0.62,
      }),
    );
    puddle.rotation.x = -Math.PI / 2;
    puddle.scale.y = 0.34 + random() * 0.2;
    puddle.position.set(-12 + i * 6.2, -0.198, 10.6 + random() * 4.5);
    scene.add(puddle);
  }

  const reflectionMap = reflectionTexture();
  const reflection = new THREE.Mesh(
    new THREE.PlaneGeometry(11, 5.4),
    new THREE.MeshBasicMaterial({
      map: reflectionMap,
      transparent: true,
      opacity: 0.32,
      blending: THREE.AdditiveBlending,
      depthWrite: false,
      toneMapped: false,
    }),
  );
  reflection.rotation.x = -Math.PI / 2;
  reflection.position.set(0.2, -0.17, 7.1);
  reflection.scale.set(1.1, 1.25, 1);
  scene.add(reflection);

  const lampGlow = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: glow,
      color: 0xffd48b,
      transparent: true,
      opacity: 0.24,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    }),
  );
  lampGlow.position.set(-6.8, 0.8, 10);
  lampGlow.scale.set(8, 4, 1);
  scene.add(lampGlow);

  return [reflectionMap];
}

function createStore(scene: any, glow: any): any {
  const store = new THREE.Group();
  scene.add(store);

  const wall = new THREE.MeshStandardMaterial({ color: 0xe4dfcf, roughness: 0.72 });
  const trim = new THREE.MeshStandardMaterial({ color: 0x43504e, roughness: 0.58, metalness: 0.25 });
  const floor = new THREE.MeshStandardMaterial({ color: 0xd8d3c4, roughness: 0.4 });
  const glass = new THREE.MeshPhysicalMaterial({
    color: 0x8cc6c2,
    roughness: 0.08,
    metalness: 0.12,
    transparent: true,
    opacity: 0.18,
    side: THREE.DoubleSide,
    depthWrite: false,
  });
  const shelf = new THREE.MeshStandardMaterial({ color: 0xb8b6ab, roughness: 0.58 });
  const dark = new THREE.MeshStandardMaterial({ color: 0x172225, roughness: 0.5 });
  const fluorescent = new THREE.MeshStandardMaterial({
    color: 0xfff8d7,
    emissive: 0xfff4c5,
    emissiveIntensity: 2.8,
    toneMapped: false,
  });
  const coolLight = new THREE.MeshStandardMaterial({
    color: 0x8ddbd4,
    emissive: 0x74d8d1,
    emissiveIntensity: 1.8,
    toneMapped: false,
  });

  addBox(store, [10.2, 0.34, 5.6], [0, 0.02, 0], new THREE.MeshStandardMaterial({ color: 0x777a73, roughness: 0.9 }));
  addBox(store, [9.7, 0.26, 5.2], [0, 3.55, 0], trim);

  // 重新排出正面墙体，让三块玻璃窗与自动门露出室内。
  addBox(store, [9.7, 0.46, 0.22], [0, 3.25, 2.51], trim);
  addBox(store, [0.45, 2.65, 0.2], [-4.62, 1.49, 2.51], trim);
  addBox(store, [0.45, 2.65, 0.2], [4.62, 1.49, 2.51], trim);
  addBox(store, [0.38, 2.65, 0.2], [-0.95, 1.49, 2.51], trim);
  addBox(store, [0.38, 2.65, 0.2], [0.95, 1.49, 2.51], trim);

  addBox(store, [3.45, 2.35, 0.035], [-2.8, 1.45, 2.47], glass, false);
  addBox(store, [3.45, 2.35, 0.035], [2.8, 1.45, 2.47], glass, false);
  addBox(store, [1.55, 2.35, 0.035], [0, 1.45, 2.47], glass, false);
  addBox(store, [1.45, 2.34, 0.035], [0, 1.45, 2.44], glass, false);
  addBox(store, [0.045, 0.46, 0.06], [-0.16, 1.25, 2.54], trim, false);
  addBox(store, [0.045, 0.46, 0.06], [0.16, 1.25, 2.54], trim, false);

  addBox(store, [9.1, 0.16, 5.15], [0, 3.36, 0.05], wall);
  addBox(store, [9.2, 0.22, 4.85], [0, 0.17, 0], floor);
  addBox(store, [9.2, 2.9, 0.12], [0, 1.62, -2.42], wall);
  addBox(store, [0.12, 2.9, 4.9], [-4.72, 1.62, 0], wall);
  addBox(store, [0.12, 2.9, 4.9], [4.72, 1.62, 0], wall);

  for (const x of [-3.0, 0, 3.0]) {
    addBox(store, [2.0, 0.08, 0.22], [x, 3.22, 0.2], fluorescent, false);
  }

  const interiorLightA = new THREE.PointLight(0xfff4cf, 5.2, 15, 2);
  interiorLightA.position.set(-2.3, 2.5, 1.6);
  store.add(interiorLightA);
  const interiorLightB = new THREE.PointLight(0xc7fff2, 4.0, 14, 2);
  interiorLightB.position.set(2.4, 2.5, 0.7);
  store.add(interiorLightB);

  const signMaterial = new THREE.MeshBasicMaterial({
    map: storeSignTexture(),
    toneMapped: false,
  });
  addBox(store, [9.35, 1.3, 0.25], [0, 4.17, 2.48], new THREE.MeshStandardMaterial({ color: 0x0a3836, roughness: 0.46 }), false);
  const sign = new THREE.Mesh(new THREE.PlaneGeometry(9.0, 1.15), signMaterial);
  sign.position.set(0, 4.17, 2.62);
  store.add(sign);

  const signGlow = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: glow,
      color: 0x8effd9,
      transparent: true,
      opacity: 0.18,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    }),
  );
  signGlow.position.set(0, 4.16, 2.8);
  signGlow.scale.set(13, 4.2, 1);
  store.add(signGlow);

  addBox(store, [9.7, 0.18, 1.25], [0, 3.0, 3.0], trim, false);
  const canopyLight = new THREE.MeshStandardMaterial({
    color: 0xfff1cf,
    emissive: 0xffeac1,
    emissiveIntensity: 2.0,
    toneMapped: false,
  });
  addBox(store, [9.1, 0.08, 1.05], [0, 2.9, 3.02], canopyLight, false);

  for (const x of [-2.9, -1.3, 1.3, 2.9]) {
    const support = addBox(store, [0.12, 0.67, 0.12], [x, 2.62, 3.55], trim, false);
    support.rotation.z = 0;
  }

  const posterA = new THREE.Mesh(
    new THREE.PlaneGeometry(0.82, 1.12),
    new THREE.MeshBasicMaterial({ map: posterTexture('HOT', 'COFFEE', '#d7a64f', '#3a2615'), toneMapped: false }),
  );
  posterA.position.set(-3.85, 1.65, 2.52);
  store.add(posterA);
  const posterB = new THREE.Mesh(
    new THREE.PlaneGeometry(0.82, 1.12),
    new THREE.MeshBasicMaterial({ map: posterTexture('おにぎり', '100円', '#e9dfb7', '#445644'), toneMapped: false }),
  );
  posterB.position.set(3.85, 1.65, 2.52);
  store.add(posterB);

  const interior = new THREE.Group();
  interior.position.set(0, 0, 0);
  store.add(interior);

  addBox(interior, [3.8, 2.4, 0.18], [-2.55, 1.45, -2.32], new THREE.MeshStandardMaterial({ color: 0x45746e, roughness: 0.3, emissive: 0x183e3d, emissiveIntensity: 1.1 }));
  for (let row = 0; row < 4; row++) {
    addBox(interior, [3.65, 0.05, 0.3], [-2.55, 0.56 + row * 0.52, -2.18], shelf);
    for (let col = 0; col < 9; col++) {
      const color = [0xe26354, 0x65a9c2, 0xdfbd50, 0x77a26a][(row + col) % 4] ?? 0xe26354;
      addBox(interior, [0.18, 0.26, 0.2], [-4.05 + col * 0.38, 0.73 + row * 0.52, -2.08], new THREE.MeshStandardMaterial({ color, roughness: 0.65 }));
    }
  }

  for (const x of [-2.65, 0.1, 2.55]) {
    const aisle = new THREE.Group();
    aisle.position.set(x, 0, -0.15);
    addBox(aisle, [0.12, 1.7, 3.3], [0, 0.85, 0], shelf);
    for (let row = 0; row < 4; row++) {
      addBox(aisle, [1.25, 0.05, 3.1], [0, 0.33 + row * 0.36, 0], shelf);
      for (let col = 0; col < 7; col++) {
        addBox(
          aisle,
          [0.22, 0.18, 0.16],
          [-0.43 + (col % 5) * 0.2, 0.46 + row * 0.36, -1.25 + Math.floor(col / 5) * 0.42],
          new THREE.MeshStandardMaterial({
            color: [0xd26351, 0xe6b94e, 0x659f81, 0x7195be][(row + col) % 4] ?? 0xd26351,
            roughness: 0.7,
          }),
        );
      }
    }
    interior.add(aisle);
  }

  const counter = new THREE.Group();
  counter.position.set(3.2, 0, 1.2);
  addBox(counter, [1.55, 1.05, 0.8], [0, 0.62, 0], new THREE.MeshStandardMaterial({ color: 0x5b6a68, roughness: 0.5 }));
  addBox(counter, [1.45, 0.08, 0.72], [0, 1.18, 0], new THREE.MeshStandardMaterial({ color: 0x242b2d, roughness: 0.35 }));
  addBox(counter, [0.42, 0.4, 0.28], [-0.42, 1.42, 0.05], triGenericDark(), false);
  const monitor = addBox(counter, [0.4, 0.28, 0.05], [-0.42, 1.48, 0.16], new THREE.MeshBasicMaterial({ color: 0x8ef2ca, toneMapped: false }), false);
  monitor.rotation.x = -0.18;
  const person = new THREE.Group();
  person.position.set(0.1, 0, -0.4);
  const body = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.3, 0.9, 8), new THREE.MeshStandardMaterial({ color: 0x263b3a, roughness: 0.8 }));
  body.position.y = 0.65;
  person.add(body);
  const head = new THREE.Mesh(new THREE.SphereGeometry(0.2, 12, 10), new THREE.MeshStandardMaterial({ color: 0xc6a184, roughness: 0.86 }));
  head.position.y = 1.27;
  person.add(head);
  interior.add(counter, person);

  const vending = new THREE.Group();
  vending.position.set(5.75, 0, 1.55);
  vending.rotation.y = -0.08;
  addBox(vending, [1.05, 2.0, 0.82], [0, 1.0, 0], new THREE.MeshStandardMaterial({ color: 0xcac8be, roughness: 0.55 }));
  const vendingFace = new THREE.Mesh(
    new THREE.PlaneGeometry(0.88, 1.55),
    new THREE.MeshBasicMaterial({ map: vendingTexture(), toneMapped: false }),
  );
  vendingFace.position.set(0, 1.1, 0.42);
  vending.add(vendingFace);
  const vendingLight = new THREE.PointLight(0x9effd6, 1.5, 6, 2);
  vendingLight.position.set(5.75, 1.55, 2.25);
  store.add(vendingLight);
  scene.add(vending);

  const bins = new THREE.Group();
  bins.position.set(-5.9, 0.35, 2.2);
  for (let i = 0; i < 3; i++) {
    addBox(bins, [0.36, 0.7, 0.38], [i * 0.42, 0, 0], new THREE.MeshStandardMaterial({ color: [0x45655c, 0x6e6550, 0x4c5967][i] ?? 0x45655c, roughness: 0.8 }));
  }
  scene.add(bins);

  const plant = new THREE.Group();
  plant.position.set(-4.8, 0.22, 3.95);
  addBox(plant, [1.05, 0.42, 0.52], [0, 0, 0], new THREE.MeshStandardMaterial({ color: 0x676c61, roughness: 0.9 }));
  for (let i = 0; i < 7; i++) {
    const leaf = new THREE.Mesh(
      new THREE.ConeGeometry(0.1, 0.76, 4),
      new THREE.MeshStandardMaterial({ color: 0x3c6043, roughness: 0.95 }),
    );
    leaf.position.set((i % 4) * 0.18 - 0.27, 0.48, Math.floor(i / 4) * 0.16 - 0.08);
    leaf.rotation.z = (i - 3) * 0.08;
    plant.add(leaf);
  }
  scene.add(plant);

  store.traverse((object: any) => {
    if (object.isMesh) {
      object.castShadow = !object.material?.transparent;
      object.receiveShadow = true;
    }
  });

  return {
    store,
    sign,
    signMaterial,
    signGlow,
    interiorLights: [interiorLightA, interiorLightB],
    fluorescent,
    coolLight,
    canopyLight,
  };
}

function triGenericDark(): any {
  return new THREE.MeshStandardMaterial({ color: 0x1b2425, roughness: 0.5 });
}

function createStreetFurniture(scene: any, glow: any): any[] {
  const metal = new THREE.MeshStandardMaterial({ color: 0x3d4140, roughness: 0.55, metalness: 0.55 });
  const dark = new THREE.MeshStandardMaterial({ color: 0x1a2021, roughness: 0.7 });
  const lampGlowMaterial = new THREE.MeshBasicMaterial({ color: 0xffe4a9, toneMapped: false });

  const pole = new THREE.Group();
  pole.position.set(-7.1, 0, 7.4);
  const mast = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.14, 6.4, 8), metal);
  mast.position.y = 3.2;
  mast.castShadow = true;
  pole.add(mast);
  const arm = rod(new THREE.Vector3(0, 6.2, 0), new THREE.Vector3(1.55, 6.2, 0), 0.07, metal);
  pole.add(arm);
  const lamp = new THREE.Mesh(new THREE.SphereGeometry(0.16, 12, 8), lampGlowMaterial);
  lamp.position.set(1.55, 6.09, 0);
  pole.add(lamp);
  const lampHalo = new THREE.Sprite(
    new THREE.SpriteMaterial({
      map: glow,
      color: 0xffd68e,
      transparent: true,
      opacity: 0.32,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    }),
  );
  lampHalo.position.copy(lamp.position);
  lampHalo.scale.set(4.5, 4.5, 1);
  pole.add(lampHalo);
  const streetLight = new THREE.PointLight(0xffd292, 3.1, 14, 2);
  streetLight.position.set(-5.55, 5.75, 7.4);
  scene.add(streetLight);
  scene.add(pole);

  const utility = new THREE.Group();
  utility.position.set(7.8, 0, 9.6);
  const utilityPole = new THREE.Mesh(new THREE.CylinderGeometry(0.16, 0.23, 8.6, 8), new THREE.MeshStandardMaterial({ color: 0x303639, roughness: 0.85 }));
  utilityPole.position.y = 4.3;
  utility.add(utilityPole);
  for (const y of [6.7, 7.35]) {
    addBox(utility, [2.2, 0.12, 0.14], [0, y, 0], dark);
  }
  scene.add(utility);

  const wireMaterial = new THREE.MeshBasicMaterial({ color: 0x11181b });

  for (const y of [6.72, 7.36]) {
    const curve = new THREE.CatmullRomCurve3([
      new THREE.Vector3(-24, y + 1.6, 10),
      new THREE.Vector3(-6, y + 0.15, 9.2),
      new THREE.Vector3(7.8, y, 9.6),
      new THREE.Vector3(25, y + 1.15, 10.2),
    ]);
    scene.add(new THREE.Mesh(new THREE.TubeGeometry(curve, 48, 0.018, 4, false), wireMaterial));
  }

  const bicycle = new THREE.Group();
  bicycle.position.set(-4.6, 0.33, 5.2);
  bicycle.rotation.y = 0.15;
  const wheelGeometry = new THREE.TorusGeometry(0.34, 0.024, 6, 20);

  for (const x of [-0.48, 0.48]) {
    const wheel = new THREE.Mesh(wheelGeometry, metal);
    wheel.position.set(x, 0.34, 0);
    bicycle.add(wheel);
  }

  bicycle.add(rod(new THREE.Vector3(-0.48, 0.34, 0), new THREE.Vector3(0.05, 0.82, 0), 0.025, metal));
  bicycle.add(rod(new THREE.Vector3(0.48, 0.34, 0), new THREE.Vector3(0.05, 0.82, 0), 0.025, metal));
  bicycle.add(rod(new THREE.Vector3(0.48, 0.34, 0), new THREE.Vector3(0.2, 0.36, 0), 0.025, metal));
  bicycle.add(rod(new THREE.Vector3(0.05, 0.82, 0), new THREE.Vector3(0.16, 0.98, 0), 0.022, metal));
  bicycle.add(rod(new THREE.Vector3(-0.48, 0.34, 0), new THREE.Vector3(0.05, 0.34, 0), 0.022, metal));
  addBox(bicycle, [0.3, 0.05, 0.08], [0.04, 0.86, 0], dark);
  scene.add(bicycle);

  return [streetLight];
}

function createRain(scene: any, mobile: boolean, random: () => number): any {
  const count = mobile ? 72 : 128;
  const positions = new Float32Array(count * 6);
  const speeds = new Float32Array(count);

  for (let i = 0; i < count; i++) {
    const x = (random() - 0.5) * 34;
    const z = 4 + random() * 22;
    const y = random() * 13;
    const length = 0.12 + random() * 0.32;
    positions[i * 6] = x;
    positions[i * 6 + 1] = y;
    positions[i * 6 + 2] = z;
    positions[i * 6 + 3] = x - 0.035;
    positions[i * 6 + 4] = y + length;
    positions[i * 6 + 5] = z;
    speeds[i] = 2.6 + random() * 3.4;
  }

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  const rain = new THREE.LineSegments(
    geometry,
    new THREE.LineBasicMaterial({
      color: 0xb8d4d2,
      transparent: true,
      opacity: 0.27,
      depthWrite: false,
    }),
  );
  rain.frustumCulled = false;
  scene.add(rain);
  return { rain, speeds };
}

export const init: PageModule['init'] = async () => {
  const root = document.querySelector<HTMLElement>('[data-konbini-scene]');
  const canvas = document.querySelector<HTMLCanvasElement>('[data-konbini-canvas]');
  const loading = document.querySelector<HTMLElement>('[data-konbini-loading]');
  const fallback = document.querySelector<HTMLElement>('[data-konbini-fallback]');
  const copy = document.querySelector<HTMLElement>('[data-konbini-copy]');
  const resetButton = document.querySelector<HTMLButtonElement>('[data-konbini-reset]');
  const lightsButton = document.querySelector<HTMLButtonElement>('[data-konbini-lights]');

  if (root === null || canvas === null) {
    return;
  }

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const mobile = window.matchMedia('(max-width: 720px)').matches;
  const random = mulberry32(20261018);
  let renderer: any;

  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: !mobile,
      powerPreference: 'high-performance',
      alpha: false,
    });
  } catch {
    loading?.setAttribute('hidden', '');
    fallback?.removeAttribute('hidden');
    return;
  }

  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, mobile ? 1.45 : 1.8));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.18;
  renderer.shadowMap.enabled = !mobile;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(0x071018);
  scene.fog = new THREE.FogExp2(0x0c161b, 0.018);

  const camera = new THREE.PerspectiveCamera(
    47,
    root.clientWidth / Math.max(1, root.clientHeight),
    0.1,
    100,
  );
  const target = new THREE.Vector3(mobile ? 0.25 : -1.2, mobile ? 2.2 : 2.0, 0.7);
  const baseRadius = mobile ? MOBILE_RADIUS : DESKTOP_RADIUS;
  const baseAzimuth = mobile ? 0.18 : BASE_AZIMUTH;
  const basePolar = mobile ? 1.41 : BASE_POLAR;
  let radius = reducedMotion ? baseRadius : baseRadius + 6.5;
  let azimuth = baseAzimuth - 0.1;
  let polar = basePolar + 0.02;
  let desiredRadius = baseRadius;
  let desiredAzimuth = baseAzimuth;
  let desiredPolar = basePolar;
  let intro = reducedMotion ? 1 : 0;
  let lastInteraction = 0;
  let idleBaseAzimuth = baseAzimuth;
  let lightsOn = true;
  let lightMix = 1;

  const glow = glowTexture();
  createSky(scene, glow);
  createNeighbourhood(scene, random);
  const streetTextures = createStreet(scene, glow, random);
  const store = createStore(scene, glow);
  const streetLights = createStreetFurniture(scene, glow);
  const rain = createRain(scene, mobile, random);

  const hemisphere = new THREE.HemisphereLight(0x88aab8, 0x0a0d0d, 0.76);
  scene.add(hemisphere);
  const moonLight = new THREE.DirectionalLight(0xb7d0df, 1.18);
  moonLight.position.set(-11, 18, 13);
  moonLight.castShadow = !mobile;
  moonLight.shadow.mapSize.set(1024, 1024);
  moonLight.shadow.camera.near = 1;
  moonLight.shadow.camera.far = 50;
  moonLight.shadow.camera.left = -16;
  moonLight.shadow.camera.right = 16;
  moonLight.shadow.camera.top = 16;
  moonLight.shadow.camera.bottom = -16;
  moonLight.shadow.bias = -0.0003;
  scene.add(moonLight);
  const cityFill = new THREE.DirectionalLight(0x7b6179, 0.36);
  cityFill.position.set(10, 7, -12);
  scene.add(cityFill);

  const setCamera = (): void => {
    const horizontal = Math.sin(polar) * radius;
    camera.position.set(
      target.x + Math.sin(azimuth) * horizontal,
      target.y + Math.cos(polar) * radius,
      target.z + Math.cos(azimuth) * horizontal,
    );
    camera.lookAt(target);
  };

  const resize = (): void => {
    const width = root.clientWidth;
    const height = root.clientHeight;
    renderer.setSize(width, height, false);
    camera.aspect = width / Math.max(1, height);
    camera.updateProjectionMatrix();
  };

  let activePointer: number | null = null;
  let lastPointer: Vec2 = { x: 0, y: 0 };
  const pointers = new Map<number, Vec2>();
  let pinchDistance = 0;

  const markInteraction = (): void => {
    lastInteraction = performance.now();
    idleBaseAzimuth = desiredAzimuth;
    intro = 1;
  };

  const onPointerDown = (event: PointerEvent): void => {
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    activePointer = event.pointerId;
    lastPointer = { x: event.clientX, y: event.clientY };
    markInteraction();
    canvas.setPointerCapture(event.pointerId);
    root.classList.add('is-dragging');
  };

  const onPointerMove = (event: PointerEvent): void => {
    if (!pointers.has(event.pointerId)) {
      return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size > 1) {
      const [first, second] = Array.from(pointers.values());

      if (first !== undefined && second !== undefined) {
        const nextDistance = Math.hypot(first.x - second.x, first.y - second.y);

        if (pinchDistance > 0) {
          desiredRadius = Math.min(29, Math.max(12, desiredRadius - (nextDistance - pinchDistance) * 0.075));
        }

        pinchDistance = nextDistance;
      }

      return;
    }

    if (activePointer !== event.pointerId) {
      return;
    }

    const dx = event.clientX - lastPointer.x;
    const dy = event.clientY - lastPointer.y;
    lastPointer = { x: event.clientX, y: event.clientY };
    desiredAzimuth -= dx * 0.004;
    desiredPolar = Math.min(1.52, Math.max(1.08, desiredPolar + dy * 0.0028));
    markInteraction();
  };

  const onPointerUp = (event: PointerEvent): void => {
    pointers.delete(event.pointerId);
    if (activePointer === event.pointerId) {
      activePointer = null;
    }
    pinchDistance = 0;
    if (pointers.size === 0) {
      root.classList.remove('is-dragging');
    }
  };

  const onWheel = (event: WheelEvent): void => {
    event.preventDefault();
    desiredRadius = Math.min(29, Math.max(12, desiredRadius + event.deltaY * 0.011));
    markInteraction();
  };

  const onKeyDown = (event: KeyboardEvent): void => {
    const step = 0.08;

    if (event.key === 'ArrowLeft') {
      desiredAzimuth += step;
    } else if (event.key === 'ArrowRight') {
      desiredAzimuth -= step;
    } else if (event.key === 'ArrowUp') {
      desiredPolar = Math.max(1.08, desiredPolar - step * 0.55);
    } else if (event.key === 'ArrowDown') {
      desiredPolar = Math.min(1.52, desiredPolar + step * 0.55);
    } else if (event.key === '+' || event.key === '=') {
      desiredRadius = Math.max(12, desiredRadius - 1.2);
    } else if (event.key === '-' || event.key === '_') {
      desiredRadius = Math.min(29, desiredRadius + 1.2);
    } else {
      return;
    }

    event.preventDefault();
    markInteraction();
  };

  const resetCamera = (): void => {
    desiredAzimuth = baseAzimuth;
    desiredPolar = basePolar;
    desiredRadius = baseRadius;
    lastInteraction = performance.now();
    idleBaseAzimuth = baseAzimuth;
    intro = 1;
  };

  const toggleLights = (): void => {
    lightsOn = !lightsOn;
    lightsButton?.setAttribute('aria-pressed', lightsOn ? 'true' : 'false');
    lightsButton?.classList.toggle('is-lit', lightsOn);
    const label = lightsButton?.querySelector<HTMLElement>('[data-konbini-lights-label]');

    if (label !== null && label !== undefined) {
      label.textContent = lightsOn ? '营业灯已开' : '营业灯已关';
    }
  };

  const onContextLost = (event: Event): void => {
    event.preventDefault();
    loading?.setAttribute('hidden', '');
    fallback?.removeAttribute('hidden');
  };

  canvas.addEventListener('pointerdown', onPointerDown);
  canvas.addEventListener('pointermove', onPointerMove);
  canvas.addEventListener('pointerup', onPointerUp);
  canvas.addEventListener('pointercancel', onPointerUp);
  canvas.addEventListener('wheel', onWheel, { passive: false });
  canvas.addEventListener('keydown', onKeyDown);
  canvas.addEventListener('webglcontextlost', onContextLost);
  resetButton?.addEventListener('click', resetCamera);
  lightsButton?.addEventListener('click', toggleLights);
  window.addEventListener('resize', resize);

  const clock = new THREE.Clock();
  let frame = 0;
  let ready = false;

  const animate = (): void => {
    frame = window.requestAnimationFrame(animate);

    if (document.hidden) {
      return;
    }

    const delta = Math.min(0.05, clock.getDelta());
    const elapsed = clock.elapsedTime;
    intro = Math.min(1, intro + delta * 0.3);

    const idleSeconds = (performance.now() - lastInteraction) / 1000 - 4.8;

    if (!reducedMotion && idleSeconds > 0) {
      // 只围绕用户最后停留的角度做小幅呼吸，避免长时间漂移后把前景杆件带进画面。
      desiredAzimuth = idleBaseAzimuth + Math.sin(idleSeconds * 0.24) * 0.075;
    }

    const smooth = 1 - Math.pow(0.001, delta);
    const introOffset = (1 - intro) * (1 - intro) * 6.5;
    radius += (desiredRadius + introOffset - radius) * Math.min(1, delta * 2.7);
    azimuth += (desiredAzimuth - azimuth) * smooth;
    polar += (desiredPolar - polar) * smooth;
    setCamera();

    lightMix += ((lightsOn ? 1 : 0) - lightMix) * Math.min(1, delta * 4);
    store.interiorLights.forEach((light: any, index: number) => {
      light.intensity = (index === 0 ? 5.2 : 4.0) * lightMix;
    });
    store.fluorescent.emissiveIntensity = 2.8 * lightMix;
    store.coolLight.emissiveIntensity = 1.8 * lightMix;
    store.canopyLight.emissiveIntensity = 2.0 * lightMix;
    store.signMaterial.color.setScalar(0.35 + lightMix * 0.65);
    store.signGlow.material.opacity = 0.18 * lightMix;

    const flicker = 0.97 + Math.sin(elapsed * 7.4) * 0.018 + Math.sin(elapsed * 13.3) * 0.012;
    store.signGlow.material.opacity *= flicker;
    streetLights[0].intensity = 3.1 * flicker;

    if (!reducedMotion) {
      const positions = rain.rain.geometry.attributes.position;

      for (let i = 0; i < rain.speeds.length; i++) {
        const speed = rain.speeds[i] ?? 3;
        let headY = positions.getY(i * 2);
        headY -= speed * delta;
        const length = 0.13 + (i % 5) * 0.06;

        if (headY < 0.1) {
          headY = 11 + (i % 7);
        }

        positions.setY(i * 2, headY);
        positions.setY(i * 2 + 1, headY + length);
      }

      positions.needsUpdate = true;
    }

    renderer.render(scene, camera);

    if (!ready) {
      ready = true;
      loading?.classList.add('is-hidden');
      root.classList.add('is-ready');
      window.setTimeout(() => loading?.setAttribute('hidden', ''), 700);
      copy?.classList.add('is-visible');
    }
  };

  setCamera();
  resize();
  animate();

  return () => {
    window.cancelAnimationFrame(frame);
    window.removeEventListener('resize', resize);
    canvas.removeEventListener('pointerdown', onPointerDown);
    canvas.removeEventListener('pointermove', onPointerMove);
    canvas.removeEventListener('pointerup', onPointerUp);
    canvas.removeEventListener('pointercancel', onPointerUp);
    canvas.removeEventListener('wheel', onWheel);
    canvas.removeEventListener('keydown', onKeyDown);
    canvas.removeEventListener('webglcontextlost', onContextLost);
    resetButton?.removeEventListener('click', resetCamera);
    lightsButton?.removeEventListener('click', toggleLights);

    scene.traverse((object: any) => {
      object.geometry?.dispose?.();
      const materials = Array.isArray(object.material) ? object.material : [object.material];
      materials.forEach((material: any) => {
        material?.map?.dispose?.();
        material?.dispose?.();
      });
    });

    streetTextures.forEach((texture: any) => texture?.dispose?.());
    glow.dispose();
    renderer.dispose();
    renderer.forceContextLoss?.();
  };
};
