"""Regenerates docs/mobile/openapi.yaml from the Laravel route list plus the per-route metadata below.

    python3 docs/mobile/openapi-src/generate.py        (needs PyYAML and a working `php artisan`)

Adding or changing a /api/v1 route means: add its line to M below (summary, request body, response schema), add any new
schema to components.py, regenerate, commit the YAML. tests/Feature/Api/V1/OpenApiTest.php fails until route and spec agree
and also checks real responses against these schemas.
"""
import json, os, subprocess, sys, re
import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..'))

routes = json.loads(subprocess.check_output(['php', 'artisan', 'route:list', '--path=api/v1', '--json'], cwd=ROOT, stderr=subprocess.DEVNULL))

def ref(n): return {'$ref': f'#/components/schemas/{n}'}
def data(schema): return {'type': 'object', 'required': ['data'], 'properties': {'data': schema}}
def arr(schema): return {'type': 'array', 'items': schema}
S = lambda **k: {'type': 'string', **k}
I = lambda **k: {'type': 'integer', **k}
N = lambda **k: {'type': 'number', **k}
B = lambda **k: {'type': 'boolean', **k}
O = lambda props=None, required=None, **k: {'type': 'object', **({'properties': props} if props else {}), **({'required': required} if required else {}), **k}
DATE = S(format='date'); DT = S(format='date-time'); UUID = S(format='uuid')
OBJ = {'type': 'object', 'additionalProperties': True}
OK = data(O({'ok': B()}))

device = ref('DeviceInfo')
signin_resp = data(ref('AuthResult'))

# name -> (tag, summary, request_body(or None), response_schema, extra)
M = {
 'achievements': ('Progress', 'Badges with progress, XP and level (server-calculated)', None, data(ref('Achievements')), {}),
 'announcements': ('Content', 'Published announcements for this member', None, data(arr(ref('Announcement'))), {}),
 'app.config': ('App', 'Version policy, maintenance, features and public settings (public)', None, data(ref('AppConfig')), {'security': False, 'headers': True}),
 'auth.forgot-password': ('Auth', 'Email a password reset link (always 200)', O({'email': S(format='email')}, ['email']), data(O({'message': S()})), {'security': False}),
 'auth.guest': ('Auth', 'Start a guest account', O({'device': device}, ['device']), signin_resp, {'security': False, 'status': 201}),
 'auth.login': ('Auth', 'Sign in and receive a bearer token', O({'email': S(format='email'), 'password': S(), 'device': device}, ['email', 'password', 'device']), signin_resp, {'security': False}),
 'auth.logout': ('Auth', 'Revoke this install\'s token', None, OK, {}),
 'auth.logout-all': ('Auth', 'Revoke every token of the account', None, OK, {}),
 'auth.refresh': ('Auth', 'Rotate the token (old one stops working)', None, data(O({'token': ref('Token')})), {}),
 'auth.register': ('Auth', 'Create an account', O({'name': S(), 'email': S(format='email'), 'password': S(minLength=8), 'device': device}, ['name', 'email', 'password', 'device']), signin_resp, {'security': False, 'status': 201}),
 'auth.reset-password': ('Auth', 'Set a new password with a reset token', O({'token': S(), 'email': S(format='email'), 'password': S(minLength=8)}, ['token', 'email', 'password']), OK, {'security': False}),
 'auth.sessions': ('Auth', 'Signed-in installs of this account', None, data(arr(ref('Device'))), {}),
 'auth.sessions.destroy': ('Auth', 'Sign another install out', None, OK, {}),
 'banners': ('Content', 'Published banners (optional ?placement=)', None, data(arr(ref('Banner'))), {}),
 'content.changes': ('Sync', 'Documents changed after a cursor', None, ref('ContentChanges'), {}),
 'content.manifest': ('Sync', 'Sync cursor, per-collection versions and counts', None, data(ref('ContentManifest')), {}),
 'content.snapshot': ('Sync', 'Full paginated list of one collection', None, ref('ContentSnapshot'), {}),
 'dashboard': ('Dashboard', 'Everything on the home screen in one call', None, data(ref('Dashboard')), {}),
 'exercises.index': ('Library', 'Search and filter the exercise library', None, ref('ExercisePage'), {}),
 'exercises.show': ('Library', 'One exercise (a teaser with locked=true if the plan lacks it)', None, data(ref('Exercise')), {}),
 'exercises.alternatives': ('Library', 'Same-muscle alternatives the member can do', None, data(arr(ref('Exercise'))), {}),
 'food-logs.store': ('Nutrition', 'Log a food; the server converts and stores the macros', O({'food_id': I(), 'logged_on': DATE, 'meal': S(enum=['breakfast', 'lunch', 'snack', 'dinner']), 'quantity': N(), 'unit': S(enum=['grams', 'serving']), 'serving_id': I()}, ['food_id', 'logged_on', 'meal', 'quantity', 'unit']), data(O({'log': ref('FoodLog'), 'day': ref('DayNutrition'), 'badges': arr(ref('BadgeBrief'))})), {'status': 201}),
 'food-logs.batch': ('Nutrition', 'Log several foods as one meal', O({'logged_on': DATE, 'meal': S(), 'items': arr(O({'food_id': I(), 'grams': N()}, ['food_id', 'grams']))}, ['logged_on', 'meal', 'items']), data(O({'logs': arr(ref('FoodLog')), 'day': ref('DayNutrition')})), {'status': 201}),
 'food-logs.copy': ('Nutrition', 'Copy a day (meals already present are left alone)', O({'from': DATE, 'to': DATE, 'meal': S()}, ['from', 'to']), data(O({'copied': I(), 'skipped': arr(S()), 'day': ref('DayNutrition')})), {}),
 'food-logs.update': ('Nutrition', 'Change the amount or meal of an entry', O({'quantity': N(), 'unit': S(enum=['grams', 'serving']), 'serving_id': I(), 'meal': S()}, ['quantity', 'unit']), data(O({'log': ref('FoodLog'), 'day': ref('DayNutrition')})), {}),
 'food-logs.destroy': ('Nutrition', 'Delete an entry', None, data(O({'day': ref('DayNutrition')})), {}),
 'foods.store': ('Nutrition', 'Create a private custom food', O({'name': S(), 'brand': S(), 'diet_type': S(), 'kcal_100g': N(), 'protein_100g': N(), 'carbs_100g': N(), 'fat_100g': N(), 'fibre_100g': N(), 'servings': arr(O({'label': S(), 'grams': N()}))}, ['name', 'diet_type', 'kcal_100g', 'protein_100g', 'carbs_100g', 'fat_100g']), data(ref('Food')), {'status': 201}),
 'foods.favorites': ('Nutrition', 'Favourite foods', None, data(arr(ref('Food'))), {}),
 'foods.recent': ('Nutrition', 'Foods logged most often recently', None, data(arr(ref('Food'))), {}),
 'foods.search': ('Nutrition', 'Search the shared and the member\'s own foods', None, data(arr(ref('Food'))), {}),
 'foods.show': ('Nutrition', 'One food', None, data(ref('Food')), {}),
 'foods.destroy': ('Nutrition', 'Delete a custom food (logged meals keep their numbers)', None, OK, {}),
 'foods.favorite': ('Nutrition', 'Add to favourites', None, data(O({'food_id': I(), 'favorite': B()})), {}),
 'foods.unfavorite': ('Nutrition', 'Remove from favourites', None, data(O({'food_id': I(), 'favorite': B()})), {}),
 'habits.show': ('Habits', 'Water, creatine, sleep and mobility for a day', None, data(ref('Habits')), {}),
 'habits.creatine': ('Habits', 'Tick creatine', O({'taken': B()}, ['taken']), data(ref('Habits')), {}),
 'habits.mobility': ('Habits', 'Set mobility minutes', O({'minutes': I()}, ['minutes']), data(ref('Habits')), {}),
 'habits.sleep': ('Habits', 'Log sleep', O({'sleep_hours': N(), 'sleep_quality': I(), 'bedtime': S(), 'wake_time': S()}, ['sleep_hours']), data(ref('Habits')), {}),
 'habits.water.add': ('Habits', 'Add a drink', O({'amount_ml': I(minimum=10)}, ['amount_ml']), data(ref('Habits')), {}),
 'habits.water.undo': ('Habits', 'Undo the last drink', None, data(ref('Habits')), {}),
 'help.index': ('Content', 'Published help articles', None, data(arr(ref('HelpArticle'))), {}),
 'help.show': ('Content', 'One help article', None, data(ref('HelpArticle')), {}),
 'leaderboard': ('Progress', 'Weekly XP ranking (opted-in members only)', None, data(O({'enabled': B(), 'opted_in': B(), 'entries': arr(O({'rank': I(), 'name': S(), 'xp': I(), 'level': I(), 'me': B()}))})), {}),
 'me.show': ('Account', 'The signed-in member', None, data(ref('User')), {}),
 'me.update': ('Account', 'Change name / email', O({'name': S(), 'email': S(format='email')}, ['name']), data(ref('User')), {}),
 'me.destroy': ('Account', 'Delete the account and everything it owns', O({'password': S(), 'confirm': S(enum=['DELETE'])}), OK, {}),
 'me.claim': ('Account', 'Turn a guest into a registered account', O({'email': S(format='email'), 'password': S(), 'password_confirmation': S()}, ['email', 'password', 'password_confirmation']), data(ref('User')), {}),
 'me.device.push': ('Notifications', 'Register this phone\'s push token', O({'provider': S(enum=['fcm']), 'token': S()}, ['provider', 'token']), data(O({'device': ref('Device')})), {}),
 'me.device.push.destroy': ('Notifications', 'Forget this phone\'s push token', None, OK, {}),
 'me.notifications': ('Notifications', 'Push categories, quiet hours, lock-screen detail', None, data(ref('NotificationPrefs')), {}),
 'me.notifications.update': ('Notifications', 'Change push preferences (stored on the server)', ref('NotificationPrefsUpdate'), data(ref('NotificationPrefs')), {}),
 'me.onboarding': ('Account', 'Complete the first-run profile', ref('BodyProfile'), data(ref('User')), {}),
 'me.password': ('Account', 'Change password (signs out other devices)', O({'current_password': S(), 'password': S()}, ['current_password', 'password']), OK, {}),
 'me.profile': ('Account', 'The body / goal profile', None, data(ref('Profile')), {}),
 'me.profile.update': ('Account', 'Update the body / goal profile', ref('BodyProfile'), data(ref('Profile')), {}),
 'me.program': ('Programs', 'Switch to a program', O({'program_id': I()}, ['program_id']), data(O({'program_id': I(), 'plan_started_on': DATE})), {}),
 'me.settings': ('Account', 'Preferences and reminder times', None, data(ref('Settings')), {}),
 'me.settings.update': ('Account', 'Update preferences and reminder times', ref('Settings'), data(ref('Settings')), {}),
 'media.banner': ('Media', 'Banner image (signed URL, expires)', None, None, {'security': False, 'binary': True}),
 'media.exercise': ('Media', 'Exercise video / image (signed URL, supports Range)', None, None, {'security': False, 'binary': True}),
 'membership.show': ('Membership', 'Current plan, subscription and entitlements', None, data(ref('Membership')), {}),
 'membership.entitlements': ('Membership', 'Entitlement map only (cheap re-check)', None, data(O({'entitlements': ref('EntitlementMap'), 'limits': OBJ, 'plan_slug': S(), 'checked_at': DT})), {}),
 'membership.plans': ('Membership', 'Published membership plans (public)', None, data(arr(ref('MembershipPlan'))), {'security': False}),
 'membership.verify': ('Membership', 'Verify a store purchase on the server', O({'provider': S(enum=['apple', 'google']), 'product_id': S(), 'receipt': S(), 'transaction_id': S()}, ['provider', 'product_id', 'receipt']), data(ref('Membership')), {}),
 'membership.restore': ('Membership', 'Re-verify purchases after a reinstall', O({'purchases': arr(O({'provider': S(), 'product_id': S(), 'receipt': S()}, ['provider', 'product_id', 'receipt']))}, ['purchases']), data(OBJ), {}),
 'nutrition.calculate': ('Nutrition', 'What an amount of food is worth (nothing saved)', O({'food_id': I(), 'quantity': N(), 'unit': S(enum=['grams', 'serving']), 'serving_id': I()}, ['food_id', 'quantity', 'unit']), data(O({'food': ref('Food'), 'grams': N(), 'serving_label': S(), 'macros': ref('Macros')})), {}),
 'nutrition.day': ('Nutrition', 'Totals, targets, remaining and meals for a day', None, data(ref('DayNutrition')), {}),
 'nutrition.history': ('Nutrition', 'Calories and protein per day', None, data(arr(OBJ)), {}),
 'nutrition.meal-plan': ('Nutrition', 'Suggested meals that fit the targets', None, data(arr(OBJ)), {}),
 'nutrition.targets': ('Nutrition', 'Active targets and how they were calculated', None, data(OBJ), {}),
 'nutrition.recalculate': ('Nutrition', 'Change goal / diet and rebuild targets', O({'goal': S(), 'diet_preference': S(), 'calorie_cycling': B()}, ['goal', 'diet_preference']), data(OBJ), {}),
 'plan-items.alternatives': ('Plan', 'Swap candidates for a plan row', None, data(arr(OBJ)), {}),
 'plan-items.swap': ('Plan', 'Swap a plan exercise', O({'exercise_id': I(), 'permanent': B()}, ['exercise_id']), data(OBJ), {'status': 201}),
 'plan-items.unswap': ('Plan', 'Undo a swap', None, OK, {}),
 'plan.day': ('Plan', 'One day with exercises and next targets', None, data(ref('PlanDay')), {}),
 'plan.complete': ('Plan', 'Mark a day done without the player', O({'date': DATE}), data(OBJ), {}),
 'plan.skip': ('Plan', 'Skip a day', O({'date': DATE}), data(OBJ), {}),
 'plan.unskip': ('Plan', 'Undo a skip', O({'date': DATE}), data(OBJ), {}),
 'plan.week': ('Plan', 'The week with a status per day', None, data(OBJ), {}),
 'posture.exercises': ('Posture coach', 'Exercises the coach can judge, with rule versions', None, data(arr(OBJ)), {}),
 'posture.rule': ('Posture coach', 'The JSON rule (schema 1) for an exercise', None, data(ref('PostureRule')), {}),
 'posture.sessions.store': ('Posture coach', 'Save the numeric summary of a camera set', ref('PostureSessionSave'), data(OBJ), {'status': 201}),
 'programs.index': ('Programs', 'Published programs (premium ones as locked cards)', None, data(arr(ref('Program'))), {}),
 'programs.show': ('Programs', 'A program with phases and the weekly template', None, data(ref('ProgramDetail')), {}),
 'progress.measurements': ('Progress', 'Measurements, newest first', None, data(arr(ref('Measurement'))), {}),
 'progress.measurements.store': ('Progress', 'Save a day\'s measurements (merges blanks)', ref('MeasurementSave'), data(O({'measurement': ref('Measurement'), 'badges': arr(ref('BadgeBrief'))})), {'status': 201}),
 'progress.measurements.destroy': ('Progress', 'Delete a measurement', None, OK, {}),
 'progress.photos': ('Progress', 'Progress photos (metadata)', None, data(arr(OBJ)), {}),
 'progress.photos.store': ('Progress', 'Upload a progress photo (multipart; re-encoded server-side)', O({'photo': S(format='binary'), 'pose': S(enum=['front', 'side', 'back']), 'taken_on': DATE, 'notes': S()}, ['photo', 'pose', 'taken_on']), data(OBJ), {'status': 201, 'multipart': True}),
 'progress.photos.show': ('Progress', 'The photo bytes (owner only)', None, None, {'binary': True}),
 'progress.photos.destroy': ('Progress', 'Delete a photo and its file', None, OK, {}),
 'progress.records': ('Progress', 'Personal records per exercise', None, data(arr(OBJ)), {}),
 'progress.strength': ('Progress', 'Strength curve of an exercise', None, data(OBJ), {}),
 'progress.summary': ('Progress', 'Weight trend, BMI, body fat', None, data(OBJ), {}),
 'saved-meals.index': ('Nutrition', 'Saved meals with recomputed totals', None, data(arr(OBJ)), {}),
 'saved-meals.store': ('Nutrition', 'Save a meal', O({'name': S(), 'meal': S(), 'items': arr(O({'food_id': I(), 'grams': N()}, ['food_id', 'grams']))}, ['name', 'items']), data(OBJ), {'status': 201}),
 'saved-meals.destroy': ('Nutrition', 'Delete a saved meal', None, OK, {}),
 'saved-meals.log': ('Nutrition', 'Log a saved meal on a day', O({'logged_on': DATE, 'meal': S()}), data(OBJ), {'status': 201}),
 'sync.batch': ('Sync', 'Replay operations queued offline (idempotent per op_id)', ref('SyncBatch'), data(O({'results': arr(ref('SyncResult')), 'server_time': DT})), {}),
 'workout-sessions.index': ('Workouts', 'Workout history (cursor pagination)', None, OBJ, {}),
 'workout-sessions.store': ('Workouts', 'Start (or resume) a workout', O({'weekday': I(minimum=1, maximum=7), 'date': DATE, 'client_uuid': UUID}), data(ref('WorkoutSession')), {'status': 201}),
 'workout-sessions.active': ('Workouts', 'The unfinished workout, if any', None, data(ref('WorkoutSession')), {}),
 'workout-sessions.show': ('Workouts', 'A workout with exercises, sets and resume state', None, data(ref('WorkoutSession')), {}),
 'workout-sessions.abandon': ('Workouts', 'Abandon a workout', None, OK, {}),
 'workout-sessions.exercises.alternatives': ('Workouts', 'Alternatives for an exercise in the session', None, data(arr(OBJ)), {}),
 'workout-sessions.exercises.skip': ('Workouts', 'Skip an exercise', None, OK, {}),
 'workout-sessions.exercises.swap': ('Workouts', 'Swap an exercise in the session', O({'exercise_id': I()}, ['exercise_id']), data(ref('WorkoutSession')), {}),
 'workout-sessions.finish': ('Workouts', 'Finish a workout (idempotent)', O({'active_seconds': I()}), data(O({'ok': B(), 'session_id': I(), 'status': S()})), {}),
 'workout-sessions.sets.store': ('Workouts', 'Log or correct a set (same exercise + number updates)', O({'session_exercise_id': I(), 'set_number': I(minimum=1, maximum=30), 'weight_kg': N(), 'reps': I(), 'duration_seconds': I(), 'rpe': N(), 'is_warmup': B()}, ['session_exercise_id', 'set_number']), data(O({'set': ref('SessionSet'), 'exercise_status': S(), 'is_pr': B(), 'personal_records': arr(OBJ), 'xp': I(), 'totals': OBJ})), {'status': 201}),
 'workout-sessions.sets.destroy': ('Workouts', 'Delete a set', None, OK, {}),
 'workout-sessions.state': ('Workouts', 'Save resume state (older saves are ignored)', ref('SessionStateSave'), data(O({'applied': B(), 'client_state': OBJ})), {}),
 'workout-sessions.summary': ('Workouts', 'Results of a completed workout', None, data(OBJ), {}),
}

paths = {}
missing = []
for r in routes:
    name = r['name'][3:]
    uri = '/' + r['uri'][len('api/v1/'):]
    method = r['method'].replace('|HEAD', '').lower()
    if name not in M:
        missing.append(name); continue
    tag, summary, body, resp, extra = M[name]
    auth = any(m.endswith('Authenticate:sanctum') for m in r['middleware'])
    ent = [m.split(':', 1)[1] for m in r['middleware'] if m.split('\\')[-1].startswith('EnsureEntitled:')]
    onboarded = any(m.endswith('EnsureOnboardedApi') for m in r['middleware'])
    params = []
    for p in re.findall(r'\{(\w+)\}', uri):
        typ = {'collection': S(enum=['programs', 'exercises', 'foods', 'badges', 'membership_plans', 'announcements', 'banners', 'help']), 'exercise': S(), 'slug': S()}.get(p, I())
        if p == 'exercise' and not uri.startswith('/exercises') and not uri.startswith('/progress/strength') and not uri.startswith('/posture'): typ = I()
        params.append({'name': p, 'in': 'path', 'required': True, 'schema': typ})
    op = {'tags': [tag], 'summary': summary, 'operationId': name.replace('.', '_').replace('-', '_')}
    if params: op['parameters'] = params
    if not auth and extra.get('security') is False: op['security'] = []
    if ent: op['x-entitlement'] = ent[0]
    if onboarded: op['x-requires-onboarding'] = True
    if body is not None:
        ct = 'multipart/form-data' if extra.get('multipart') else 'application/json'
        op['requestBody'] = {'required': bool(body.get('required') or '$ref' in body), 'content': {ct: {'schema': body}}}
    code = str(extra.get('status', 200))
    responses = {}
    if extra.get('binary'):
        responses['200'] = {'description': 'The file', 'content': {'application/octet-stream': {'schema': {'type': 'string', 'format': 'binary'}}}}
    else:
        responses[code] = {'description': 'Success', 'content': {'application/json': {'schema': resp}}}
    if auth: responses['401'] = {'$ref': '#/components/responses/Unauthenticated'}
    if ent: responses['403'] = {'$ref': '#/components/responses/Forbidden'}
    if body is not None or params: responses['422'] = {'$ref': '#/components/responses/Invalid'}
    if params: responses['404'] = {'$ref': '#/components/responses/NotFound'}
    responses['429'] = {'$ref': '#/components/responses/RateLimited'}
    op['responses'] = responses
    paths.setdefault(uri, {})[method] = op
if missing:
    print('MISSING METADATA FOR', missing); sys.exit(1)

exec(open(os.path.join(HERE, 'components.py')).read())

doc = {
 'openapi': '3.1.0',
 'info': {
  'title': 'IronPulse mobile API', 'version': '1.0.0',
  'description': open(os.path.join(HERE, 'intro.md')).read(),
 },
 'servers': [{'url': 'https://your-domain.example/api/v1', 'description': 'Production (HTTPS only)'}, {'url': 'http://localhost:8000/api/v1', 'description': 'Local development'}],
 'tags': [{'name': t} for t in sorted({op['tags'][0] for p in paths.values() for op in p.values()})],
 'security': [{'bearerAuth': []}],
 'paths': dict(sorted(paths.items())),
 'components': components,
}
class D(yaml.SafeDumper):
    def ignore_aliases(self, data): return True
with open(os.path.join(ROOT, 'docs', 'mobile', 'openapi.yaml'), 'w') as f:
    yaml.dump(doc, f, Dumper=D, sort_keys=False, width=120, allow_unicode=True)
print('wrote', len(paths), 'paths', sum(len(p) for p in paths.values()), 'operations')
