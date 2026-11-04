"""Phase 1 smoke tests."""
from django.test import SimpleTestCase
from django.urls import reverse


class HomePageTests(SimpleTestCase):
    def test_homepage(self):
        response = self.client.get(reverse("home"), secure=True)
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, "Research, <em>thoughtfully</em> scheduled")

    def test_accessible_main_landmark(self):
        response = self.client.get(reverse("home"), secure=True)
        self.assertContains(response, '<main id="main-content">')

    def test_health_endpoint(self):
        response = self.client.get(reverse("health"), secure=True)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"status": "ok"})

    def test_admin_login_exists(self):
        response = self.client.get("/admin/login/", secure=True)
        self.assertEqual(response.status_code, 200)
